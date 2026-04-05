<?php

if (!defined('AFF_OWNER_CODE')) {
    define('AFF_OWNER_CODE', 'D-0042');
}
if (!defined('AFF_DEFAULT_GESTOR')) {
    define('AFF_DEFAULT_GESTOR', 'G001');
}
if (!defined('AFF_SESSION_TTL_SECONDS')) {
    define('AFF_SESSION_TTL_SECONDS', 8 * 3600);
}
if (!defined('AFF_LOCK_WINDOW_SECONDS')) {
    define('AFF_LOCK_WINDOW_SECONDS', 15 * 60);
}
if (!defined('AFF_LOCK_THRESHOLD')) {
    define('AFF_LOCK_THRESHOLD', 5);
}
if (!defined('AFF_LOCK_DURATION_SECONDS')) {
    define('AFF_LOCK_DURATION_SECONDS', 30 * 60);
}

function aff_session_start_if_needed(): void {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
}

function aff_session_pdo(): ?PDO {
    global $pdo;
    return ($pdo instanceof PDO) ? $pdo : null;
}

function aff_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $key) {
        $value = $_SERVER[$key] ?? '';
        if (!is_string($value) || trim($value) === '') {
            continue;
        }
        if ($key === 'HTTP_X_FORWARDED_FOR') {
            $parts = array_map('trim', explode(',', $value));
            return (string)($parts[0] ?? '');
        }
        return trim($value);
    }
    return '';
}

function aff_user_agent(): string {
    return substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
}

function aff_session_expires_at(): string {
    return date('Y-m-d H:i:s', time() + AFF_SESSION_TTL_SECONDS);
}

function aff_close_session_record(PDO $pdo, string $sessionId, string $column): void {
    if ($sessionId === '' || !in_array($column, ['logged_out_at', 'revoked_at'], true)) {
        return;
    }
    $st = $pdo->prepare("UPDATE affiliate_user_sessions SET {$column}=?, last_seen_at=? WHERE session_id=?");
    $st->execute([aff_now(), aff_now(), $sessionId]);
}

function aff_clear_rac_session(): void {
    aff_session_start_if_needed();
    unset($_SESSION['affiliate_auth'], $_SESSION['affiliate_csrf_token']);
}

function aff_auth_context(): array {
    aff_session_start_if_needed();
    if (!empty($_SESSION['admin_logged_in'])) {
        return [
            'authenticated' => true,
            'user_id' => null,
            'role' => 'admin',
            'username' => 'erp_admin',
            'display_name' => 'ERP Admin',
            'owner_id' => null,
            'gestor_id' => null,
            'source' => 'erp_admin',
        ];
    }
    $auth = $_SESSION['affiliate_auth'] ?? null;
    if (!is_array($auth) || empty($auth['authenticated'])) {
        return [
            'authenticated' => false,
            'user_id' => null,
            'role' => '',
            'username' => '',
            'display_name' => '',
            'owner_id' => null,
            'gestor_id' => null,
            'source' => 'none',
        ];
    }
    $expiresAt = (string)($auth['expires_at'] ?? '');
    $sessionId = (string)($auth['session_id'] ?? session_id());
    if ($expiresAt !== '' && strtotime($expiresAt) !== false && strtotime($expiresAt) < time()) {
        $pdo = aff_session_pdo();
        if ($pdo instanceof PDO) {
            aff_close_session_record($pdo, $sessionId, 'logged_out_at');
            aff_record_audit($pdo, [
                'owner_id' => isset($auth['owner_id']) ? (int)$auth['owner_id'] : null,
                'gestor_id' => isset($auth['gestor_id']) ? (string)$auth['gestor_id'] : null,
                'event_type' => 'affiliate_session_expired',
                'severity' => 'warning',
                'message' => 'Sesión RAC expirada por tiempo',
                'context' => ['username' => (string)($auth['username'] ?? ''), 'role' => (string)($auth['role'] ?? '')],
            ]);
        }
        aff_clear_rac_session();
        return [
            'authenticated' => false,
            'user_id' => null,
            'role' => '',
            'username' => '',
            'display_name' => '',
            'owner_id' => null,
            'gestor_id' => null,
            'source' => 'expired',
        ];
    }
    $pdo = aff_session_pdo();
    if ($pdo instanceof PDO && $sessionId !== '') {
        $st = $pdo->prepare("SELECT id FROM affiliate_user_sessions WHERE session_id=? AND revoked_at IS NULL AND logged_out_at IS NULL AND expires_at >= NOW() LIMIT 1");
        $st->execute([$sessionId]);
        if (!$st->fetchColumn()) {
            aff_clear_rac_session();
            return [
                'authenticated' => false,
                'user_id' => null,
                'role' => '',
                'username' => '',
                'display_name' => '',
                'owner_id' => null,
                'gestor_id' => null,
                'source' => 'revoked',
            ];
        }
        $pdo->prepare("UPDATE affiliate_user_sessions SET last_seen_at=?, expires_at=? WHERE session_id=?")->execute([aff_now(), aff_session_expires_at(), $sessionId]);
        $_SESSION['affiliate_auth']['expires_at'] = aff_session_expires_at();
    }
    return [
        'authenticated' => true,
        'user_id' => isset($auth['user_id']) ? (int)$auth['user_id'] : null,
        'role' => (string)($auth['role'] ?? ''),
        'username' => (string)($auth['username'] ?? ''),
        'display_name' => (string)($auth['display_name'] ?? ''),
        'owner_id' => isset($auth['owner_id']) ? (int)$auth['owner_id'] : null,
        'gestor_id' => isset($auth['gestor_id']) ? (string)$auth['gestor_id'] : null,
        'session_id' => $sessionId,
        'expires_at' => $expiresAt,
        'source' => 'rac',
    ];
}

function aff_is_authenticated(): bool {
    return aff_auth_context()['authenticated'] === true;
}

function aff_auth_role(): string {
    return (string)aff_auth_context()['role'];
}

function aff_role_allowed(array $roles): bool {
    $ctx = aff_auth_context();
    if (!$ctx['authenticated']) {
        return false;
    }
    return in_array($ctx['role'], $roles, true);
}

function aff_allowed_ui_roles(): array {
    $role = aff_auth_role();
    if ($role === 'admin') {
        return ['dueno', 'gestor', 'admin'];
    }
    if ($role === 'owner') {
        return ['dueno'];
    }
    if ($role === 'gestor') {
        return ['gestor'];
    }
    return [];
}

function aff_ui_role_from_auth(): string {
    $role = aff_auth_role();
    if ($role === 'owner') {
        return 'dueno';
    }
    if ($role === 'gestor') {
        return 'gestor';
    }
    return 'admin';
}

function aff_now(): string {
    return date('Y-m-d H:i:s');
}

function aff_b64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function aff_b64url_decode(string $value): string {
    $value = strtr($value, '-_', '+/');
    $pad = strlen($value) % 4;
    if ($pad) {
        $value .= str_repeat('=', 4 - $pad);
    }
    return base64_decode($value, true) ?: '';
}

function aff_link_secret(): string {
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $env = getenv('AFFILIATE_LINK_SECRET');
    if (is_string($env) && $env !== '') {
        $secret = $env;
        return $secret;
    }
    $secret = hash('sha256', __FILE__ . '|' . ($_SERVER['SERVER_NAME'] ?? 'palweb') . '|rac-affiliate');
    return $secret;
}

function aff_get_setting(PDO $pdo, string $key, string $default = ''): string {
    $st = $pdo->prepare("SELECT setting_value FROM affiliate_settings WHERE setting_key=? LIMIT 1");
    $st->execute([$key]);
    $value = $st->fetchColumn();
    return is_string($value) ? $value : $default;
}

function aff_set_setting(PDO $pdo, string $key, string $value): void {
    $st = $pdo->prepare("INSERT INTO affiliate_settings (setting_key, setting_value) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), updated_at=CURRENT_TIMESTAMP");
    $st->execute([$key, $value]);
}

function aff_telegram_bot_token(PDO $pdo = null): string {
    $env = getenv('AFFILIATE_TELEGRAM_BOT_TOKEN');
    if (is_string($env) && trim($env) !== '') {
        return trim($env);
    }
    if ($pdo instanceof PDO) {
        return trim(aff_get_setting($pdo, 'telegram_bot_token', ''));
    }
    return '';
}

function aff_telegram_enabled(PDO $pdo = null): bool {
    return aff_telegram_bot_token($pdo) !== '';
}

function aff_column_exists(PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
}

function aff_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!aff_column_exists($pdo, $table, $column)) {
        $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
    }
}

function aff_table_exists(PDO $pdo, string $table): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?");
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
}

function aff_ensure_tables(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL UNIQUE,
        password_hash VARCHAR(255) NOT NULL,
        role ENUM('admin','owner','gestor') NOT NULL,
        display_name VARCHAR(120) NOT NULL,
        owner_id INT NULL,
        gestor_id VARCHAR(20) NULL,
        status ENUM('active','suspended') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_user_sessions (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        session_id VARCHAR(128) NOT NULL UNIQUE,
        role VARCHAR(20) NOT NULL,
        ip_address VARCHAR(80) NULL,
        user_agent VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        logged_out_at DATETIME NULL,
        revoked_at DATETIME NULL,
        CONSTRAINT fk_aff_user_session_user FOREIGN KEY (user_id) REFERENCES affiliate_users(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_login_attempts (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(80) NOT NULL,
        ip_address VARCHAR(80) NULL,
        user_agent VARCHAR(255) NULL,
        success TINYINT(1) NOT NULL DEFAULT 0,
        attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_owners (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_code VARCHAR(20) NOT NULL UNIQUE,
        owner_name VARCHAR(120) NOT NULL,
        available_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        blocked_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        total_balance DECIMAL(12,2) NOT NULL DEFAULT 0,
        status ENUM('active','suspended') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'phone', "VARCHAR(40) NULL AFTER owner_name");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'whatsapp_number', "VARCHAR(40) NULL AFTER phone");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'geo_zone', "VARCHAR(120) NULL AFTER whatsapp_number");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'subscription_plan', "VARCHAR(40) NOT NULL DEFAULT 'basic' AFTER geo_zone");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'managed_service', "TINYINT(1) NOT NULL DEFAULT 0 AFTER subscription_plan");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'reputation_score', "DECIMAL(5,2) NOT NULL DEFAULT 80 AFTER managed_service");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'fraud_risk', "VARCHAR(20) NOT NULL DEFAULT 'BAJO' AFTER reputation_score");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'monthly_fee', "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER fraud_risk");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'subscription_due_at', "DATETIME NULL AFTER monthly_fee");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'advertising_budget', "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER subscription_due_at");
    aff_add_column_if_missing($pdo, 'affiliate_owners', 'ads_active', "TINYINT(1) NOT NULL DEFAULT 0 AFTER advertising_budget");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_gestores (
        id VARCHAR(20) PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        earnings DECIMAL(12,2) NOT NULL DEFAULT 0,
        links INT NOT NULL DEFAULT 0,
        conversions INT NOT NULL DEFAULT 0,
        rating DECIMAL(4,2) NOT NULL DEFAULT 0,
        status ENUM('active','suspended') NOT NULL DEFAULT 'active',
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    aff_add_column_if_missing($pdo, 'affiliate_gestores', 'phone', "VARCHAR(40) NULL AFTER name");
    aff_add_column_if_missing($pdo, 'affiliate_gestores', 'telegram_chat_id', "VARCHAR(80) NULL AFTER phone");
    aff_add_column_if_missing($pdo, 'affiliate_gestores', 'masked_code', "VARCHAR(60) NULL AFTER telegram_chat_id");
    aff_add_column_if_missing($pdo, 'affiliate_gestores', 'reputation_score', "DECIMAL(5,2) NOT NULL DEFAULT 80 AFTER masked_code");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_products (
        id VARCHAR(20) PRIMARY KEY,
        owner_id INT NOT NULL,
        name VARCHAR(190) NOT NULL,
        category VARCHAR(80) NOT NULL,
        price DECIMAL(12,2) NOT NULL DEFAULT 0,
        stock INT NOT NULL DEFAULT 0,
        commission DECIMAL(12,2) NOT NULL DEFAULT 0,
        commission_pct DECIMAL(6,2) NOT NULL DEFAULT 0,
        icon VARCHAR(16) NOT NULL DEFAULT '📦',
        brand VARCHAR(80) NOT NULL DEFAULT 'Generic',
        description TEXT NULL,
        clicks INT NOT NULL DEFAULT 0,
        leads INT NOT NULL DEFAULT 0,
        sales INT NOT NULL DEFAULT 0,
        trending TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_aff_product_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    aff_add_column_if_missing($pdo, 'affiliate_products', 'image_url', "VARCHAR(255) NULL AFTER icon");
    aff_add_column_if_missing($pdo, 'affiliate_products', 'image_webp_url', "VARCHAR(255) NULL AFTER image_url");
    aff_add_column_if_missing($pdo, 'affiliate_products', 'image_thumb_url', "VARCHAR(255) NULL AFTER image_webp_url");
    aff_add_column_if_missing($pdo, 'affiliate_products', 'is_featured', "TINYINT(1) NOT NULL DEFAULT 0 AFTER trending");
    aff_add_column_if_missing($pdo, 'affiliate_products', 'sponsor_rank', "INT NOT NULL DEFAULT 0 AFTER is_featured");
    aff_add_column_if_missing($pdo, 'affiliate_products', 'price_mode', "VARCHAR(20) NOT NULL DEFAULT 'fixed' AFTER sponsor_rank");
    aff_add_column_if_missing($pdo, 'affiliate_products', 'coupon_label', "VARCHAR(120) NULL AFTER price_mode");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_leads (
        id VARCHAR(20) PRIMARY KEY,
        owner_id INT NOT NULL,
        product_id VARCHAR(20) NOT NULL,
        gestor_id VARCHAR(20) NOT NULL,
        client VARCHAR(80) NOT NULL,
        lead_date DATE NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'new',
        commission DECIMAL(12,2) NOT NULL DEFAULT 0,
        trace_code VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_aff_lead_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE CASCADE,
        CONSTRAINT fk_aff_lead_product FOREIGN KEY (product_id) REFERENCES affiliate_products(id) ON DELETE CASCADE,
        CONSTRAINT fk_aff_lead_gestor FOREIGN KEY (gestor_id) REFERENCES affiliate_gestores(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'client_name', "VARCHAR(120) NULL AFTER client");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'client_phone', "VARCHAR(40) NULL AFTER client_name");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'triggered_at', "DATETIME NULL AFTER lead_date");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'contact_opened_at', "DATETIME NULL AFTER triggered_at");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'sold_at', "DATETIME NULL AFTER contact_opened_at");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'no_sale_at', "DATETIME NULL AFTER sold_at");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'fraud_flag', "TINYINT(1) NOT NULL DEFAULT 0 AFTER no_sale_at");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'locked_commission', "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER commission");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'gestor_share', "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER locked_commission");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'platform_share', "DECIMAL(12,2) NOT NULL DEFAULT 0 AFTER gestor_share");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'ref_token', "VARCHAR(255) NULL AFTER trace_code");
    aff_add_column_if_missing($pdo, 'affiliate_leads', 'contact_url', "TEXT NULL AFTER ref_token");
    $pdo->exec("ALTER TABLE affiliate_leads MODIFY COLUMN status VARCHAR(30) NOT NULL DEFAULT 'new'");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_audit_alerts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NULL,
        owner_label VARCHAR(160) NOT NULL,
        alert_type ENUM('fraud','inactive') NOT NULL DEFAULT 'fraud',
        metric VARCHAR(190) NOT NULL,
        risk VARCHAR(40) NOT NULL,
        color VARCHAR(20) NOT NULL DEFAULT '#ef5350',
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_aff_alert_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_wallet_movements (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        lead_id VARCHAR(20) NULL,
        movement_type VARCHAR(40) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        delta_available DECIMAL(12,2) NOT NULL DEFAULT 0,
        delta_blocked DECIMAL(12,2) NOT NULL DEFAULT 0,
        available_before DECIMAL(12,2) NOT NULL DEFAULT 0,
        available_after DECIMAL(12,2) NOT NULL DEFAULT 0,
        blocked_before DECIMAL(12,2) NOT NULL DEFAULT 0,
        blocked_after DECIMAL(12,2) NOT NULL DEFAULT 0,
        note VARCHAR(190) NOT NULL,
        metadata_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_aff_wallet_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_wallet_topups (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        payment_method VARCHAR(40) NOT NULL,
        reference_code VARCHAR(120) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        reviewed_at DATETIME NULL,
        reviewed_by VARCHAR(80) NULL,
        CONSTRAINT fk_aff_topup_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_billing_charges (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NOT NULL,
        charge_type VARCHAR(30) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        reference_code VARCHAR(120) NOT NULL UNIQUE,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        note VARCHAR(255) NULL,
        due_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        paid_at DATETIME NULL,
        settled_by VARCHAR(80) NULL,
        CONSTRAINT fk_aff_charge_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_payment_reconciliations (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NULL,
        payment_channel VARCHAR(40) NOT NULL,
        reference_code VARCHAR(120) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        target_type VARCHAR(30) NOT NULL,
        target_id BIGINT NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'matched',
        note VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_external_payments (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NULL,
        payment_channel VARCHAR(40) NOT NULL,
        reference_code VARCHAR(120) NOT NULL,
        amount DECIMAL(12,2) NOT NULL DEFAULT 0,
        payer_name VARCHAR(120) NULL,
        raw_payload_json JSON NULL,
        source_type VARCHAR(20) NOT NULL DEFAULT 'extract',
        matched_target_type VARCHAR(30) NULL,
        matched_target_id BIGINT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'pending',
        note VARCHAR(255) NULL,
        paid_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_aff_external_payment_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE SET NULL,
        UNIQUE KEY uniq_aff_external_payment_ref (reference_code, amount, payment_channel)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_audit_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        owner_id INT NULL,
        gestor_id VARCHAR(20) NULL,
        product_id VARCHAR(20) NULL,
        lead_id VARCHAR(20) NULL,
        event_type VARCHAR(60) NOT NULL,
        severity VARCHAR(20) NOT NULL DEFAULT 'info',
        message VARCHAR(255) NOT NULL,
        context_json JSON NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_trace_links (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        product_id VARCHAR(20) NOT NULL,
        gestor_id VARCHAR(20) NOT NULL,
        owner_id INT NOT NULL,
        ref_token VARCHAR(255) NOT NULL UNIQUE,
        masked_ref VARCHAR(64) NOT NULL,
        clicks INT NOT NULL DEFAULT 0,
        contact_opens INT NOT NULL DEFAULT 0,
        last_opened_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_aff_trace_product FOREIGN KEY (product_id) REFERENCES affiliate_products(id) ON DELETE CASCADE,
        CONSTRAINT fk_aff_trace_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE CASCADE,
        CONSTRAINT fk_aff_trace_gestor FOREIGN KEY (gestor_id) REFERENCES affiliate_gestores(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_settings (
        setting_key VARCHAR(80) PRIMARY KEY,
        setting_value TEXT NULL,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function aff_seed_demo_data(PDO $pdo): void {
    $owners = [
        ['D-0042', 'ElectroHavana', '+53 5555-0042', '5355550042', 'La Habana', 'basic', 0, 91, 'BAJO', 47500, 12750, 60250, 'active', 1500, date('Y-m-d H:i:s', strtotime('+10 days')), 2000, 1],
        ['D-0078', 'Electrónica Sur', '+53 5555-0078', '5355550078', 'Santiago', 'managed', 1, 62, 'ALTO', 25000, 18000, 43000, 'active', 4500, date('Y-m-d H:i:s', strtotime('+3 days')), 5000, 1],
        ['D-0031', 'Tienda Miramar', '+53 5555-0031', '5355550031', 'La Habana', 'basic', 0, 40, 'ALTO', 0, 0, 0, 'suspended', 1500, date('Y-m-d H:i:s', strtotime('-5 days')), 0, 0],
        ['D-0055', 'La Habana Electronics', '+53 5555-0055', '5355550055', 'La Habana', 'pro', 0, 74, 'MEDIO', 31000, 9000, 40000, 'active', 3000, date('Y-m-d H:i:s', strtotime('+20 days')), 3500, 1],
    ];
    $stOwner = $pdo->prepare("INSERT INTO affiliate_owners (owner_code, owner_name, phone, whatsapp_number, geo_zone, subscription_plan, managed_service, reputation_score, fraud_risk, available_balance, blocked_balance, total_balance, status, monthly_fee, subscription_due_at, advertising_budget, ads_active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE owner_name=VALUES(owner_name), phone=VALUES(phone), whatsapp_number=VALUES(whatsapp_number), geo_zone=VALUES(geo_zone), subscription_plan=VALUES(subscription_plan), managed_service=VALUES(managed_service), reputation_score=VALUES(reputation_score), fraud_risk=VALUES(fraud_risk), available_balance=VALUES(available_balance), blocked_balance=VALUES(blocked_balance), total_balance=VALUES(total_balance), status=VALUES(status), monthly_fee=VALUES(monthly_fee), subscription_due_at=VALUES(subscription_due_at), advertising_budget=VALUES(advertising_budget), ads_active=VALUES(ads_active)");
    foreach ($owners as $row) {
        $stOwner->execute($row);
    }

    $ownerMap = [];
    foreach ($pdo->query("SELECT id, owner_code FROM affiliate_owners") as $row) {
        $ownerMap[$row['owner_code']] = (int)$row['id'];
    }

    $gestores = [
        ['G001', 'Carlos Méndez', '+53 5111-0001', '@carlos_rac', 'GEN-CMZ-01', 91, 48750, 23, 15, 4.9, 'active'],
        ['G002', 'Lisandra Pérez', '+53 5111-0002', '@lisa_rac', 'GEN-LPR-02', 86, 32100, 18, 9, 4.7, 'active'],
        ['G003', 'Yordanis Cruz', '+53 5111-0003', '@yordi_rac', 'GEN-YCZ-03', 79, 19800, 11, 5, 4.3, 'active'],
    ];
    $stGestor = $pdo->prepare("INSERT INTO affiliate_gestores (id, name, phone, telegram_chat_id, masked_code, reputation_score, earnings, links, conversions, rating, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), telegram_chat_id=VALUES(telegram_chat_id), masked_code=VALUES(masked_code), reputation_score=VALUES(reputation_score), earnings=VALUES(earnings), links=VALUES(links), conversions=VALUES(conversions), rating=VALUES(rating), status=VALUES(status)");
    foreach ($gestores as $row) {
        $stGestor->execute($row);
    }

    $products = [
      ['P001','D-0042','iPhone 13 Pro 256GB','Tecnología',85000,3,3000,3.5,'📱',null,null,'Apple','Cámara triple 12MP, chip A15 Bionic, pantalla ProMotion 120Hz. Excelente estado.',142,28,9,1,1,12,'fixed','Cupón RAC 3%'],
      ['P002','D-0042','Samsung Galaxy S22','Tecnología',62000,5,2200,3.5,'📲',null,null,'Samsung','6.1 pulgadas AMOLED, 128GB, 8GB RAM. Color negro. Desbloqueado.',98,19,6,0,0,0,'fixed',null],
      ['P003','D-0042','Nevera LG 12 pies','Electrodomésticos',120000,2,6000,5,'🧊',null,null,'LG','No frost, dispensador de agua, eficiencia energética A++. Entrega incluida.',201,41,15,1,1,20,'fixed','Entrega gratis'],
      ['P004','D-0042','Aire Acondicionado 12000 BTU','Electrodomésticos',95000,4,4750,5,'❄️',null,null,'Midea','Inverter, bajo consumo, control remoto. Incluye instalación.',315,67,22,1,1,15,'fixed','Instalación bonificada'],
      ['P005','D-0042','Moto G82 5G','Tecnología',45000,8,1800,4,'📳',null,null,'Motorola','Pantalla pOLED 6.6, 128GB, NFC, batería 5000mAh.',77,14,4,0,0,0,'fixed',null],
      ['P006','D-0042','Sofá 3 Plazas','Muebles',38000,1,1900,5,'🛋️',null,null,'Local','Tela microfibra gris, estructura de madera maciza. Excelente calidad.',55,9,2,0,0,0,'fixed',null],
      ['P007','D-0042','Laptop ASUS VivoBook 15','Tecnología',73000,3,2920,4,'💻',null,null,'ASUS','Intel Core i5, 8GB RAM, SSD 512GB, pantalla Full HD. Windows 11.',189,38,11,1,1,9,'fixed',null],
      ['P008','D-0042','Bicicleta Eléctrica','Transporte',55000,2,2750,5,'🚲',null,null,'Generic','Batería 48V, autonomía 60km, velocidad máx 35km/h. Con cargador.',134,26,8,0,0,0,'fixed',null],
    ];
    $stProduct = $pdo->prepare("INSERT INTO affiliate_products (id, owner_id, name, category, price, stock, commission, commission_pct, icon, image_url, image_webp_url, brand, description, clicks, leads, sales, trending, is_featured, sponsor_rank, price_mode, coupon_label, active)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)
        ON DUPLICATE KEY UPDATE owner_id=VALUES(owner_id), name=VALUES(name), category=VALUES(category), price=VALUES(price), stock=VALUES(stock), commission=VALUES(commission), commission_pct=VALUES(commission_pct), icon=VALUES(icon), image_url=VALUES(image_url), image_webp_url=VALUES(image_webp_url), brand=VALUES(brand), description=VALUES(description), clicks=VALUES(clicks), leads=VALUES(leads), sales=VALUES(sales), trending=VALUES(trending), is_featured=VALUES(is_featured), sponsor_rank=VALUES(sponsor_rank), price_mode=VALUES(price_mode), coupon_label=VALUES(coupon_label), active=1");
    foreach ($products as $row) {
        $row[1] = $ownerMap[$row[1]] ?? $ownerMap[AFF_OWNER_CODE];
        $stProduct->execute($row);
    }

    $leads = [
      ['L001','D-0042','P003','G001','+53 5xxx-1234','Cliente RAC','+53 5xxx-1234','2025-06-01','sold',6000,6000,4800,1200,'#LG-8821','demo-ref-1'],
      ['L002','D-0042','P001','G002','+53 5xxx-5678','Lead pendiente','+53 5xxx-5678','2025-06-02','contacted',3000,3000,2400,600,'#LG-8835','demo-ref-2'],
      ['L003','D-0042','P004','G001','+53 5xxx-9012','Cliente RAC','+53 5xxx-9012','2025-06-03','sold',4750,4750,3800,950,'#LG-8847','demo-ref-3'],
      ['L004','D-0042','P005','G003','+53 5xxx-3456','Cliente RAC','+53 5xxx-3456','2025-06-04','no_sale',1800,1800,1440,360,'#LG-8861','demo-ref-4'],
      ['L005','D-0042','P002','G001','+53 5xxx-7890','Lead pendiente','+53 5xxx-7890','2025-06-05','negotiating',2200,2200,1760,440,'#LG-8872','demo-ref-5'],
    ];
    $stLead = $pdo->prepare("INSERT INTO affiliate_leads (id, owner_id, product_id, gestor_id, client, client_name, client_phone, lead_date, status, commission, locked_commission, gestor_share, platform_share, trace_code, ref_token, triggered_at, contact_opened_at, sold_at, no_sale_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE owner_id=VALUES(owner_id), product_id=VALUES(product_id), gestor_id=VALUES(gestor_id), client=VALUES(client), client_name=VALUES(client_name), client_phone=VALUES(client_phone), lead_date=VALUES(lead_date), status=VALUES(status), commission=VALUES(commission), locked_commission=VALUES(locked_commission), gestor_share=VALUES(gestor_share), platform_share=VALUES(platform_share), trace_code=VALUES(trace_code), ref_token=VALUES(ref_token)");
    foreach ($leads as $row) {
        $row[1] = $ownerMap[$row[1]] ?? $ownerMap[AFF_OWNER_CODE];
        $status = $row[8];
        $now = date('Y-m-d H:i:s');
        $params = [$row[0],$row[1],$row[2],$row[3],$row[4],$row[5],$row[6],$row[7],$status,$row[9],$row[10],$row[11],$row[12],$row[13],$row[14],$now,$now,$status === 'sold' ? $now : null,$status === 'no_sale' ? $now : null];
        $stLead->execute($params);
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM affiliate_wallet_movements")->fetchColumn() === 0) {
        foreach ($owners as $ownerSeed) {
            $ownerId = $ownerMap[$ownerSeed[0]] ?? null;
            if (!$ownerId) {
                continue;
            }
            $available = (float)$ownerSeed[9];
            $blocked = (float)$ownerSeed[10];
            if ($available > 0) {
                $st = $pdo->prepare("INSERT INTO affiliate_wallet_movements (owner_id, movement_type, amount, delta_available, delta_blocked, available_before, available_after, blocked_before, blocked_after, note, metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$ownerId, 'seed_available', $available, $available, 0, 0, $available, 0, 0, 'Saldo inicial demo', json_encode(['seed' => true])]);
            }
            if ($blocked > 0) {
                $st = $pdo->prepare("INSERT INTO affiliate_wallet_movements (owner_id, movement_type, amount, delta_available, delta_blocked, available_before, available_after, blocked_before, blocked_after, note, metadata_json) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
                $st->execute([$ownerId, 'seed_blocked', $blocked, 0, $blocked, $available, $available, 0, $blocked, 'Garantía inicial demo', json_encode(['seed' => true])]);
            }
        }
    }
    if ((int)$pdo->query("SELECT COUNT(*) FROM affiliate_wallet_topups")->fetchColumn() === 0) {
        $stTopup = $pdo->prepare("INSERT INTO affiliate_wallet_topups (owner_id, amount, payment_method, reference_code, status, note, created_at, reviewed_at, reviewed_by) VALUES (?,?,?,?,?,?,?,?,?)");
        $stTopup->execute([$ownerMap['D-0042'], 5000, 'Transfermóvil', 'TM-1001', 'approved', 'Recarga demo aprobada', date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d H:i:s', strtotime('-2 days +10 minutes')), 'admin']);
        $stTopup->execute([$ownerMap['D-0078'], 3500, 'EnZona', 'EZ-2001', 'pending', 'Pendiente de conciliación', date('Y-m-d H:i:s', strtotime('-3 hours')), null, null]);
        $stTopup->execute([$ownerMap['D-0055'], 2200, 'Transfermóvil', 'TM-1002', 'rejected', 'Referencia inválida', date('Y-m-d H:i:s', strtotime('-1 day')), date('Y-m-d H:i:s', strtotime('-1 day +30 minutes')), 'admin']);
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM affiliate_billing_charges")->fetchColumn() === 0) {
        $stCharge = $pdo->prepare("INSERT INTO affiliate_billing_charges (owner_id, charge_type, amount, reference_code, status, note, due_at, created_at, paid_at, settled_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
        $stCharge->execute([$ownerMap['D-0078'], 'subscription', 4500, 'RAC-SUB-D0078-0001', 'pending', 'Cuota mensual managed', date('Y-m-d H:i:s', strtotime('+3 days')), date('Y-m-d H:i:s', strtotime('-2 days')), null, null]);
        $stCharge->execute([$ownerMap['D-0042'], 'advertising', 2000, 'RAC-ADS-D0042-0001', 'paid', 'Campaña destacada RAC', date('Y-m-d H:i:s', strtotime('-5 days')), date('Y-m-d H:i:s', strtotime('-6 days')), date('Y-m-d H:i:s', strtotime('-5 days')), 'admin']);
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM affiliate_external_payments")->fetchColumn() === 0) {
        $stExt = $pdo->prepare("INSERT IGNORE INTO affiliate_external_payments (owner_id, payment_channel, reference_code, amount, payer_name, raw_payload_json, source_type, status, note, paid_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)");
        $stExt->execute([$ownerMap['D-0042'] ?? null, 'Transfermóvil', 'TM-1001', 5000, 'Cliente Demo', json_encode(['seed' => true]), 'extract', 'matched', 'Extracto demo conciliado', date('Y-m-d H:i:s', strtotime('-2 days')), date('Y-m-d H:i:s', strtotime('-2 days'))]);
        $stExt->execute([$ownerMap['D-0078'] ?? null, 'Transfermóvil', 'RAC-SUB-D0078-0001', 4500, 'Electrónica Sur', json_encode(['seed' => true]), 'gateway', 'pending', 'Pendiente de conciliación', date('Y-m-d H:i:s', strtotime('-20 minutes')), date('Y-m-d H:i:s', strtotime('-20 minutes'))]);
    }

    if ((int)$pdo->query("SELECT COUNT(*) FROM affiliate_users")->fetchColumn() === 0) {
        $hash = password_hash('123456', PASSWORD_DEFAULT);
        $stUser = $pdo->prepare("INSERT INTO affiliate_users (username, password_hash, role, display_name, owner_id, gestor_id, status, created_at) VALUES (?,?,?,?,?,?,?,?)");
        $stUser->execute(['racadmin', $hash, 'admin', 'Administrador RAC', null, null, 'active', aff_now()]);
        $stUser->execute(['dueno.d0042', $hash, 'owner', 'ElectroHavana', $ownerMap['D-0042'] ?? null, null, 'active', aff_now()]);
        $stUser->execute(['dueno.d0078', $hash, 'owner', 'Electrónica Sur', $ownerMap['D-0078'] ?? null, null, 'active', aff_now()]);
        $stUser->execute(['gestor.g001', $hash, 'gestor', 'Carlos Méndez', null, 'G001', 'active', aff_now()]);
        $stUser->execute(['gestor.g002', $hash, 'gestor', 'Lisandra Pérez', null, 'G002', 'active', aff_now()]);
    }

    aff_refresh_audit_alerts($pdo);
}

function aff_owner(PDO $pdo): array {
    $ctx = aff_auth_context();
    if (!empty($ctx['owner_id'])) {
        $st = $pdo->prepare("SELECT * FROM affiliate_owners WHERE id=? LIMIT 1");
        $st->execute([(int)$ctx['owner_id']]);
    } else {
        $st = $pdo->prepare("SELECT * FROM affiliate_owners WHERE owner_code=? LIMIT 1");
        $st->execute([AFF_OWNER_CODE]);
    }
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('owner_not_found');
    }
    return $row;
}

function aff_gestor(PDO $pdo, string $gestorId): array {
    $st = $pdo->prepare("SELECT * FROM affiliate_gestores WHERE id=? LIMIT 1");
    $st->execute([$gestorId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('gestor_not_found');
    }
    return $row;
}

function aff_product(PDO $pdo, string $productId): array {
    $st = $pdo->prepare("SELECT p.*, o.owner_code, o.owner_name, o.whatsapp_number, o.phone, o.available_balance, o.blocked_balance, o.total_balance FROM affiliate_products p JOIN affiliate_owners o ON o.id = p.owner_id WHERE p.id=? AND p.active=1 LIMIT 1");
    $st->execute([$productId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('product_not_found');
    }
    return $row;
}

function aff_record_audit(PDO $pdo, array $event): void {
    $st = $pdo->prepare("INSERT INTO affiliate_audit_events (owner_id, gestor_id, product_id, lead_id, event_type, severity, message, context_json, created_at) VALUES (?,?,?,?,?,?,?,?,?)");
    $st->execute([
        $event['owner_id'] ?? null,
        $event['gestor_id'] ?? null,
        $event['product_id'] ?? null,
        $event['lead_id'] ?? null,
        $event['event_type'] ?? 'info',
        $event['severity'] ?? 'info',
        $event['message'] ?? '',
        isset($event['context']) ? json_encode($event['context'], JSON_UNESCAPED_UNICODE) : null,
        aff_now(),
    ]);
}

function aff_notify_gestor_commission(PDO $pdo, array $lead, array $product, float $gestorShare): void {
    $token = aff_telegram_bot_token($pdo);
    if ($token === '') {
        return;
    }
    $gestorId = (string)($lead['gestor_id'] ?? '');
    if ($gestorId === '') {
        return;
    }
    $st = $pdo->prepare("SELECT telegram_chat_id, name FROM affiliate_gestores WHERE id=? LIMIT 1");
    $st->execute([$gestorId]);
    $gestor = $st->fetch();
    $chatId = trim((string)($gestor['telegram_chat_id'] ?? ''));
    if ($chatId === '') {
        return;
    }
    $message = "✨ Comisión RAC ganada\n"
        . "Gestor: " . (string)($gestor['name'] ?? $gestorId) . "\n"
        . "Producto: " . (string)($product['name'] ?? ($lead['product_id'] ?? '')) . "\n"
        . "Código: " . (string)($lead['trace_code'] ?? '') . "\n"
        . "Monto: " . number_format($gestorShare, 2, '.', '') . " CUP";
    $payload = json_encode([
        'chat_id' => $chatId,
        'text' => $message,
    ], JSON_UNESCAPED_UNICODE);
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/json\r\n",
            'content' => $payload,
            'timeout' => 8,
            'ignore_errors' => true,
        ],
    ]);
    $url = 'https://api.telegram.org/bot' . rawurlencode($token) . '/sendMessage';
    $result = @file_get_contents($url, false, $ctx);
    aff_record_audit($pdo, [
        'owner_id' => (int)($lead['owner_id'] ?? 0),
        'gestor_id' => $gestorId,
        'product_id' => (string)($lead['product_id'] ?? ''),
        'lead_id' => (string)($lead['id'] ?? ''),
        'event_type' => $result === false ? 'telegram_notify_failed' : 'telegram_notified',
        'severity' => $result === false ? 'warning' : 'info',
        'message' => $result === false ? 'No fue posible notificar comisión por Telegram' : 'Comisión notificada al gestor por Telegram',
        'context' => ['chat_id' => $chatId],
    ]);
}

function aff_recompute_owner_health(PDO $pdo, int $ownerId): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE owner_id=?");
    $st->execute([$ownerId]);
    $total = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE owner_id=? AND status='sold'");
    $st->execute([$ownerId]);
    $sold = (int)$st->fetchColumn();

    $conversion = $total > 0 ? ($sold / $total) * 100 : 0.0;
    $score = 80.0;
    if ($total >= 5) {
        if ($conversion < 5) {
            $score = 38;
        } elseif ($conversion < 12) {
            $score = 55;
        } elseif ($conversion < 20) {
            $score = 70;
        } else {
            $score = min(97, 78 + ($conversion / 4));
        }
    }
    $risk = 'BAJO';
    if ($total >= 10 && $conversion < 5) {
        $risk = 'ALTO';
    } elseif ($total >= 6 && $conversion < 12) {
        $risk = 'MEDIO';
    }

    $up = $pdo->prepare("UPDATE affiliate_owners SET reputation_score=?, fraud_risk=? WHERE id=?");
    $up->execute([round($score, 2), $risk, $ownerId]);
}

function aff_recompute_gestor_health(PDO $pdo, string $gestorId): void {
    $st = $pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE gestor_id=?");
    $st->execute([$gestorId]);
    $total = (int)$st->fetchColumn();

    $st = $pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE gestor_id=? AND status='sold'");
    $st->execute([$gestorId]);
    $sold = (int)$st->fetchColumn();

    $score = $total > 0 ? min(98, max(45, 55 + (($sold / max($total, 1)) * 45))) : 80;
    $up = $pdo->prepare("UPDATE affiliate_gestores SET reputation_score=? WHERE id=?");
    $up->execute([round($score, 2), $gestorId]);
}

function aff_price_suggestions(PDO $pdo, int $ownerId): array {
    $rows = $pdo->prepare("SELECT category, price, stock FROM affiliate_products WHERE owner_id=? AND active=1 AND stock > 0 ORDER BY category, id");
    $rows->execute([$ownerId]);
    $bucket = [];
    foreach ($rows->fetchAll() as $row) {
        $category = (string)$row['category'];
        if (!isset($bucket[$category])) {
            $bucket[$category] = ['prices' => [], 'weighted_sum' => 0.0, 'stock_sum' => 0];
        }
        $price = (float)$row['price'];
        $stock = max(1, (int)$row['stock']);
        $bucket[$category]['prices'][] = $price;
        $bucket[$category]['weighted_sum'] += $price * $stock;
        $bucket[$category]['stock_sum'] += $stock;
    }

    $result = [];
    foreach ($bucket as $category => $data) {
        $freq = [];
        foreach ($data['prices'] as $price) {
            $key = number_format($price, 2, '.', '');
            $freq[$key] = ($freq[$key] ?? 0) + 1;
        }
        arsort($freq);
        $mode = (float)array_key_first($freq);
        $weighted = $data['stock_sum'] > 0 ? round($data['weighted_sum'] / $data['stock_sum'], 2) : 0.0;
        $result[] = [
            'category' => $category,
            'modePrice' => $mode,
            'weightedPrice' => $weighted,
            'items' => count($data['prices']),
        ];
    }
    return $result;
}

function aff_trace_links_for_gestor(PDO $pdo, string $gestorId): array {
    $st = $pdo->prepare("SELECT t.product_id AS productId, p.name AS product, t.ref_token AS refToken, t.masked_ref AS maskedRef, t.clicks, t.contact_opens AS contactOpens, t.last_opened_at AS lastOpenedAt, p.commission, COALESCE(l.earned, 0) AS earned, COALESCE(l.sold_count, 0) AS soldCount
        FROM affiliate_trace_links t
        JOIN affiliate_products p ON p.id=t.product_id
        LEFT JOIN (
            SELECT ref_token, ROUND(SUM(CASE WHEN status='sold' THEN gestor_share ELSE 0 END), 2) AS earned, SUM(CASE WHEN status='sold' THEN 1 ELSE 0 END) AS sold_count
            FROM affiliate_leads
            GROUP BY ref_token
        ) l ON l.ref_token=t.ref_token
        WHERE t.gestor_id=?
        ORDER BY t.id DESC
        LIMIT 100");
    $st->execute([$gestorId]);
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $rows[] = [
            'productId' => (string)$row['productId'],
            'product' => (string)$row['product'],
            'link' => 'https://www.palweb.net/rac/refer/?product=' . rawurlencode((string)$row['productId']) . '&ref=' . rawurlencode((string)$row['refToken']),
            'maskedRef' => (string)$row['maskedRef'],
            'clicks' => (int)$row['clicks'],
            'leads' => (int)$row['contactOpens'],
            'sold' => (int)$row['soldCount'],
            'ctr' => (int)$row['clicks'] > 0 ? round(((int)$row['contactOpens'] / (int)$row['clicks']) * 100, 1) : 0,
            'closeRate' => (int)$row['contactOpens'] > 0 ? round(((int)$row['soldCount'] / (int)$row['contactOpens']) * 100, 1) : 0,
            'earned' => (float)$row['earned'],
            'commission' => (float)$row['commission'],
            'lastOpenedAt' => (string)($row['lastOpenedAt'] ?? ''),
        ];
    }
    return $rows;
}

function aff_current_gestor_id(PDO $pdo): string {
    $ctx = aff_auth_context();
    if (!empty($ctx['gestor_id'])) {
        return (string)$ctx['gestor_id'];
    }
    $default = trim((string)aff_get_setting($pdo, 'default_gestor_id', AFF_DEFAULT_GESTOR));
    return $default !== '' ? $default : AFF_DEFAULT_GESTOR;
}

function aff_register_login_attempt(PDO $pdo, string $username, bool $success): void {
    $st = $pdo->prepare("INSERT INTO affiliate_login_attempts (username, ip_address, user_agent, success, attempted_at) VALUES (?,?,?,?,?)");
    $st->execute([substr($username, 0, 80), aff_client_ip(), aff_user_agent(), $success ? 1 : 0, aff_now()]);
}

function aff_login_lock_status(PDO $pdo, string $username): array {
    $windowStart = date('Y-m-d H:i:s', time() - AFF_LOCK_WINDOW_SECONDS);
    $st = $pdo->prepare("SELECT attempted_at FROM affiliate_login_attempts WHERE username=? AND success=0 AND attempted_at >= ? ORDER BY attempted_at DESC LIMIT 20");
    $st->execute([substr($username, 0, 80), $windowStart]);
    $rows = $st->fetchAll();
    $failed = count($rows);
    $lockedUntil = '';
    if ($failed >= AFF_LOCK_THRESHOLD) {
        $latest = strtotime((string)$rows[0]['attempted_at']);
        if ($latest !== false) {
            $lockedUntilTs = $latest + AFF_LOCK_DURATION_SECONDS;
            if ($lockedUntilTs > time()) {
                $lockedUntil = date('Y-m-d H:i:s', $lockedUntilTs);
            }
        }
    }
    return [
        'failed_count' => $failed,
        'locked' => $lockedUntil !== '',
        'locked_until' => $lockedUntil,
    ];
}

function aff_create_user_session(PDO $pdo, array $user): void {
    $sessionId = session_id();
    $expiresAt = aff_session_expires_at();
    $st = $pdo->prepare("INSERT INTO affiliate_user_sessions (user_id, session_id, role, ip_address, user_agent, created_at, last_seen_at, expires_at) VALUES (?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE user_id=VALUES(user_id), role=VALUES(role), ip_address=VALUES(ip_address), user_agent=VALUES(user_agent), last_seen_at=VALUES(last_seen_at), expires_at=VALUES(expires_at), logged_out_at=NULL, revoked_at=NULL");
    $st->execute([(int)$user['id'], $sessionId, (string)$user['role'], aff_client_ip(), aff_user_agent(), aff_now(), aff_now(), $expiresAt]);
    $_SESSION['affiliate_auth']['session_id'] = $sessionId;
    $_SESSION['affiliate_auth']['expires_at'] = $expiresAt;
}

function aff_login(PDO $pdo, string $username, string $password): array {
    aff_session_start_if_needed();
    $username = trim($username);
    if ($username === '' || $password === '') {
        throw new InvalidArgumentException('username_password_required');
    }
    $lock = aff_login_lock_status($pdo, $username);
    if (!empty($lock['locked'])) {
        aff_record_audit($pdo, [
            'event_type' => 'affiliate_login_locked',
            'severity' => 'warning',
            'message' => 'Intento de login bloqueado por exceso de fallos',
            'context' => ['username' => $username, 'locked_until' => $lock['locked_until'], 'ip' => aff_client_ip()],
        ]);
        throw new RuntimeException('login_locked_until:' . $lock['locked_until']);
    }
    $st = $pdo->prepare("SELECT id, username, password_hash, role, display_name, owner_id, gestor_id, status FROM affiliate_users WHERE username=? LIMIT 1");
    $st->execute([$username]);
    $user = $st->fetch();
    if (!$user || (string)$user['status'] !== 'active' || !password_verify($password, (string)$user['password_hash'])) {
        aff_register_login_attempt($pdo, $username, false);
        aff_record_audit($pdo, [
            'event_type' => 'affiliate_login_failed',
            'severity' => 'warning',
            'message' => 'Intento fallido de login RAC',
            'context' => ['username' => $username, 'ip' => aff_client_ip()],
        ]);
        throw new RuntimeException('invalid_credentials');
    }
    aff_register_login_attempt($pdo, $username, true);
    $_SESSION['affiliate_auth'] = [
        'authenticated' => true,
        'user_id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'display_name' => (string)$user['display_name'],
        'role' => (string)$user['role'],
        'owner_id' => $user['owner_id'] !== null ? (int)$user['owner_id'] : null,
        'gestor_id' => $user['gestor_id'] !== null ? (string)$user['gestor_id'] : null,
    ];
    if (empty($_SESSION['affiliate_csrf_token'])) {
        $_SESSION['affiliate_csrf_token'] = bin2hex(random_bytes(24));
    }
    aff_create_user_session($pdo, $user);
    aff_record_audit($pdo, [
        'owner_id' => $user['owner_id'] !== null ? (int)$user['owner_id'] : null,
        'gestor_id' => $user['gestor_id'] !== null ? (string)$user['gestor_id'] : null,
        'event_type' => 'affiliate_login',
        'severity' => 'info',
        'message' => 'Inicio de sesión RAC',
        'context' => ['username' => (string)$user['username'], 'role' => (string)$user['role']],
    ]);
    return aff_auth_context();
}

function aff_logout(): void {
    aff_session_start_if_needed();
    $pdo = aff_session_pdo();
    $auth = $_SESSION['affiliate_auth'] ?? [];
    if ($pdo instanceof PDO && !empty($auth['session_id'])) {
        aff_close_session_record($pdo, (string)$auth['session_id'], 'logged_out_at');
        aff_record_audit($pdo, [
            'owner_id' => isset($auth['owner_id']) ? (int)$auth['owner_id'] : null,
            'gestor_id' => isset($auth['gestor_id']) ? (string)$auth['gestor_id'] : null,
            'event_type' => 'affiliate_logout',
            'severity' => 'info',
            'message' => 'Cierre de sesión RAC',
            'context' => ['username' => (string)($auth['username'] ?? ''), 'role' => (string)($auth['role'] ?? '')],
        ]);
    }
    aff_clear_rac_session();
}

function aff_current_user_id(): ?int {
    $ctx = aff_auth_context();
    return isset($ctx['user_id']) ? (int)$ctx['user_id'] : null;
}

function aff_integration_settings(PDO $pdo): array {
    $defaultGestorId = aff_current_gestor_id($pdo);
    $st = $pdo->prepare("SELECT id, name, telegram_chat_id FROM affiliate_gestores WHERE id=? LIMIT 1");
    $st->execute([$defaultGestorId]);
    $gestor = $st->fetch() ?: ['id' => $defaultGestorId, 'name' => $defaultGestorId, 'telegram_chat_id' => ''];
    $token = aff_telegram_bot_token($pdo);
    return [
        'telegramBotToken' => $token,
        'telegramConfigured' => $token !== '',
        'defaultGestorId' => (string)$gestor['id'],
        'defaultGestorName' => (string)$gestor['name'],
        'defaultGestorChatId' => (string)($gestor['telegram_chat_id'] ?? ''),
    ];
}

function aff_save_integration_settings(PDO $pdo, array $input): array {
    $token = trim((string)($input['telegram_bot_token'] ?? ''));
    $gestorId = trim((string)($input['default_gestor_id'] ?? AFF_DEFAULT_GESTOR));
    $chatId = trim((string)($input['default_gestor_chat_id'] ?? ''));
    aff_set_setting($pdo, 'telegram_bot_token', $token);
    $st = $pdo->prepare("UPDATE affiliate_gestores SET telegram_chat_id=? WHERE id=?");
    $st->execute([$chatId, $gestorId]);
    aff_record_audit($pdo, [
        'gestor_id' => $gestorId,
        'event_type' => 'integration_settings_updated',
        'severity' => 'info',
        'message' => 'Configuración de integraciones RAC actualizada',
        'context' => ['telegram_configured' => $token !== '', 'chat_id' => $chatId !== ''],
    ]);
    return aff_integration_settings($pdo);
}

function aff_csv_escape(string $value): string {
    $value = str_replace('"', '""', $value);
    return '"' . $value . '"';
}

function aff_export_csv(array $headers, array $rows): string {
    $lines = [];
    $lines[] = implode(',', array_map('aff_csv_escape', $headers));
    foreach ($rows as $row) {
        $line = [];
        foreach ($row as $value) {
            if (is_bool($value)) {
                $value = $value ? '1' : '0';
            } elseif ($value === null) {
                $value = '';
            }
            $line[] = aff_csv_escape((string)$value);
        }
        $lines[] = implode(',', $line);
    }
    return implode("\n", $lines) . "\n";
}

function aff_xlsx_col_name(int $index): string {
    $name = '';
    $index++;
    while ($index > 0) {
        $mod = ($index - 1) % 26;
        $name = chr(65 + $mod) . $name;
        $index = (int)(($index - $mod) / 26);
        $index--;
    }
    return $name;
}

function aff_xlsx_escape($value): string {
    return htmlspecialchars((string)($value ?? ''), ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function aff_xlsx_sheet_xml(array $headers, array $rows): string {
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
    $xml .= '<row r="1">';
    foreach ($headers as $i => $header) {
        $cell = aff_xlsx_col_name($i) . '1';
        $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . aff_xlsx_escape($header) . '</t></is></c>';
    }
    $xml .= '</row>';
    foreach ($rows as $rIndex => $row) {
        $excelRow = $rIndex + 2;
        $xml .= '<row r="' . $excelRow . '">';
        foreach (array_values($row) as $cIndex => $value) {
            $cell = aff_xlsx_col_name($cIndex) . $excelRow;
            $xml .= '<c r="' . $cell . '" t="inlineStr"><is><t>' . aff_xlsx_escape($value) . '</t></is></c>';
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

function aff_export_xlsx_binary(string $sheetName, array $headers, array $rows): string {
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ziparchive_not_available');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'rac_xlsx_');
    if ($tmp === false) {
        throw new RuntimeException('xlsx_temp_file_failed');
    }
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('xlsx_open_failed');
    }
    $sheetSafe = preg_replace('/[^A-Za-z0-9 _-]/', '', $sheetName) ?: 'Sheet1';
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
        . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
        . '</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
        . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
        . '</Relationships>');
    $zip->addFromString('docProps/core.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
        . '<dc:title>RAC Export</dc:title><dc:creator>Palweb RAC</dc:creator><cp:lastModifiedBy>Palweb RAC</cp:lastModifiedBy><dcterms:created xsi:type="dcterms:W3CDTF">' . gmdate('Y-m-d\TH:i:s\Z') . '</dcterms:created></cp:coreProperties>');
    $zip->addFromString('docProps/app.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes"><Application>Palweb RAC</Application></Properties>');
    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . aff_xlsx_escape($sheetSafe) . '" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/worksheets/sheet1.xml', aff_xlsx_sheet_xml($headers, $rows));
    $zip->close();
    $binary = (string)file_get_contents($tmp);
    @unlink($tmp);
    return $binary;
}

function aff_export_leads_csv(PDO $pdo): string {
    $st = $pdo->query("SELECT l.id, l.trace_code, l.product_id, p.name AS product_name, o.owner_code, o.owner_name, l.gestor_id, g.name AS gestor_name, l.client_name, l.client_phone, l.status, l.commission, l.locked_commission, l.gestor_share, l.platform_share, l.lead_date, l.triggered_at, l.contact_opened_at, l.sold_at, l.no_sale_at
        FROM affiliate_leads l
        JOIN affiliate_products p ON p.id=l.product_id
        JOIN affiliate_owners o ON o.id=l.owner_id
        JOIN affiliate_gestores g ON g.id=l.gestor_id
        ORDER BY l.created_at DESC, l.id DESC");
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $rows[] = [
            $row['id'],
            $row['trace_code'],
            $row['product_id'],
            $row['product_name'],
            $row['owner_code'],
            $row['owner_name'],
            $row['gestor_id'],
            $row['gestor_name'],
            $row['client_name'],
            $row['client_phone'],
            $row['status'],
            $row['commission'],
            $row['locked_commission'],
            $row['gestor_share'],
            $row['platform_share'],
            $row['lead_date'],
            $row['triggered_at'],
            $row['contact_opened_at'],
            $row['sold_at'],
            $row['no_sale_at'],
        ];
    }
    return aff_export_csv(
        ['lead_id', 'trace_code', 'product_id', 'product', 'owner_code', 'owner', 'gestor_id', 'gestor', 'client_name', 'client_phone', 'status', 'commission', 'locked_commission', 'gestor_share', 'platform_share', 'lead_date', 'triggered_at', 'contact_opened_at', 'sold_at', 'no_sale_at'],
        $rows
    );
}

function aff_leads_export_rows(PDO $pdo): array {
    $st = $pdo->query("SELECT l.id, l.trace_code, l.product_id, p.name AS product_name, o.owner_code, o.owner_name, l.gestor_id, g.name AS gestor_name, l.client_name, l.client_phone, l.status, l.commission, l.locked_commission, l.gestor_share, l.platform_share, l.lead_date, l.triggered_at, l.contact_opened_at, l.sold_at, l.no_sale_at
        FROM affiliate_leads l
        JOIN affiliate_products p ON p.id=l.product_id
        JOIN affiliate_owners o ON o.id=l.owner_id
        JOIN affiliate_gestores g ON g.id=l.gestor_id
        ORDER BY l.created_at DESC, l.id DESC");
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $rows[] = [
            $row['id'], $row['trace_code'], $row['product_id'], $row['product_name'], $row['owner_code'], $row['owner_name'], $row['gestor_id'], $row['gestor_name'], $row['client_name'], $row['client_phone'], $row['status'], $row['commission'], $row['locked_commission'], $row['gestor_share'], $row['platform_share'], $row['lead_date'], $row['triggered_at'], $row['contact_opened_at'], $row['sold_at'], $row['no_sale_at'],
        ];
    }
    return $rows;
}

function aff_export_leads_xlsx(PDO $pdo): string {
    return aff_export_xlsx_binary('RAC Leads', ['lead_id', 'trace_code', 'product_id', 'product', 'owner_code', 'owner', 'gestor_id', 'gestor', 'client_name', 'client_phone', 'status', 'commission', 'locked_commission', 'gestor_share', 'platform_share', 'lead_date', 'triggered_at', 'contact_opened_at', 'sold_at', 'no_sale_at'], aff_leads_export_rows($pdo));
}

function aff_export_wallet_csv(PDO $pdo): string {
    $st = $pdo->query("SELECT m.id, o.owner_code, o.owner_name, m.lead_id, m.movement_type, m.amount, m.delta_available, m.delta_blocked, m.available_before, m.available_after, m.blocked_before, m.blocked_after, m.note, m.created_at
        FROM affiliate_wallet_movements m
        JOIN affiliate_owners o ON o.id=m.owner_id
        ORDER BY m.id DESC");
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $rows[] = [
            $row['id'],
            $row['owner_code'],
            $row['owner_name'],
            $row['lead_id'],
            $row['movement_type'],
            $row['amount'],
            $row['delta_available'],
            $row['delta_blocked'],
            $row['available_before'],
            $row['available_after'],
            $row['blocked_before'],
            $row['blocked_after'],
            $row['note'],
            $row['created_at'],
        ];
    }
    return aff_export_csv(
        ['movement_id', 'owner_code', 'owner', 'lead_id', 'movement_type', 'amount', 'delta_available', 'delta_blocked', 'available_before', 'available_after', 'blocked_before', 'blocked_after', 'note', 'created_at'],
        $rows
    );
}

function aff_wallet_export_rows(PDO $pdo): array {
    $st = $pdo->query("SELECT m.id, o.owner_code, o.owner_name, m.lead_id, m.movement_type, m.amount, m.delta_available, m.delta_blocked, m.available_before, m.available_after, m.blocked_before, m.blocked_after, m.note, m.created_at
        FROM affiliate_wallet_movements m
        JOIN affiliate_owners o ON o.id=m.owner_id
        ORDER BY m.id DESC");
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $rows[] = [
            $row['id'], $row['owner_code'], $row['owner_name'], $row['lead_id'], $row['movement_type'], $row['amount'], $row['delta_available'], $row['delta_blocked'], $row['available_before'], $row['available_after'], $row['blocked_before'], $row['blocked_after'], $row['note'], $row['created_at'],
        ];
    }
    return $rows;
}

function aff_export_wallet_xlsx(PDO $pdo): string {
    return aff_export_xlsx_binary('RAC Wallet', ['movement_id', 'owner_code', 'owner', 'lead_id', 'movement_type', 'amount', 'delta_available', 'delta_blocked', 'available_before', 'available_after', 'blocked_before', 'blocked_after', 'note', 'created_at'], aff_wallet_export_rows($pdo));
}

function aff_export_rankings_csv(PDO $pdo): string {
    $rows = [];
    foreach (aff_admin_link_rankings($pdo) as $row) {
        $rows[] = [
            $row['maskedRef'],
            $row['product'],
            $row['owner'],
            $row['gestor'],
            $row['clicks'],
            $row['leads'],
            $row['sold'],
            $row['ctr'],
            $row['closeRate'],
            $row['gestorEarned'],
            $row['platformEarned'],
            $row['lastOpenedAt'],
            $row['link'],
        ];
    }
    return aff_export_csv(
        ['masked_ref', 'product', 'owner', 'gestor', 'clicks', 'leads', 'sold', 'ctr', 'close_rate', 'gestor_earned', 'platform_earned', 'last_opened_at', 'link'],
        $rows
    );
}

function aff_rankings_export_rows(PDO $pdo): array {
    $rows = [];
    foreach (aff_admin_link_rankings($pdo) as $row) {
        $rows[] = [
            $row['maskedRef'], $row['product'], $row['owner'], $row['gestor'], $row['clicks'], $row['leads'], $row['sold'], $row['ctr'], $row['closeRate'], $row['gestorEarned'], $row['platformEarned'], $row['lastOpenedAt'], $row['link'],
        ];
    }
    return $rows;
}

function aff_export_rankings_xlsx(PDO $pdo): string {
    return aff_export_xlsx_binary('RAC Rankings', ['masked_ref', 'product', 'owner', 'gestor', 'clicks', 'leads', 'sold', 'ctr', 'close_rate', 'gestor_earned', 'platform_earned', 'last_opened_at', 'link'], aff_rankings_export_rows($pdo));
}

function aff_export_users_csv(PDO $pdo): string {
    $rows = [];
    foreach (aff_user_admin_list($pdo) as $row) {
        $rows[] = [
            $row['id'],
            $row['username'],
            $row['displayName'],
            $row['role'],
            $row['ownerId'],
            $row['ownerCode'],
            $row['gestorId'],
            $row['gestorName'],
            $row['status'],
            $row['createdAt'],
        ];
    }
    return aff_export_csv(
        ['id', 'username', 'display_name', 'role', 'owner_id', 'owner_code', 'gestor_id', 'gestor_name', 'status', 'created_at'],
        $rows
    );
}

function aff_users_export_rows(PDO $pdo): array {
    $rows = [];
    foreach (aff_user_admin_list($pdo) as $row) {
        $rows[] = [
            $row['id'], $row['username'], $row['displayName'], $row['role'], $row['ownerId'], $row['ownerCode'], $row['gestorId'], $row['gestorName'], $row['status'], $row['createdAt'],
        ];
    }
    return $rows;
}

function aff_export_users_xlsx(PDO $pdo): string {
    return aff_export_xlsx_binary('RAC Users', ['id', 'username', 'display_name', 'role', 'owner_id', 'owner_code', 'gestor_id', 'gestor_name', 'status', 'created_at'], aff_users_export_rows($pdo));
}

function aff_export_access_audit_csv(PDO $pdo): string {
    $rows = [];
    foreach (aff_access_audit_events($pdo) as $row) {
        $ctx = $row['context'] ?? [];
        $rows[] = [
            $row['eventType'],
            $row['severity'],
            $row['message'],
            $ctx['username'] ?? '',
            $ctx['role'] ?? '',
            $ctx['user_id'] ?? '',
            $ctx['masked_code'] ?? '',
            $row['createdAt'],
        ];
    }
    return aff_export_csv(
        ['event_type', 'severity', 'message', 'username', 'role', 'user_id', 'masked_code', 'created_at'],
        $rows
    );
}

function aff_access_audit_export_rows(PDO $pdo): array {
    $rows = [];
    foreach (aff_access_audit_events($pdo) as $row) {
        $ctx = $row['context'] ?? [];
        $rows[] = [
            $row['eventType'], $row['severity'], $row['message'], $ctx['username'] ?? '', $ctx['role'] ?? '', $ctx['user_id'] ?? '', $ctx['masked_code'] ?? '', $row['createdAt'],
        ];
    }
    return $rows;
}

function aff_export_access_audit_xlsx(PDO $pdo): string {
    return aff_export_xlsx_binary('RAC Access Audit', ['event_type', 'severity', 'message', 'username', 'role', 'user_id', 'masked_code', 'created_at'], aff_access_audit_export_rows($pdo));
}

function aff_health_status_path(): string {
    return __DIR__ . '/../tmp/rac_health_status.json';
}

function aff_read_health_status(): array {
    $path = aff_health_status_path();
    $base = [
        'ok' => null,
        'timestamp' => '',
        'mode' => '',
        'exit_code' => null,
        'output' => [],
        'timer' => [
            'enabled' => false,
            'active' => false,
            'next' => '',
            'last' => '',
        ],
        'service' => [
            'active' => false,
            'journal' => [],
        ],
        'summary' => [
            'checks' => 0,
            'okChecks' => 0,
            'failedChecks' => 0,
        ],
    ];
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $data = json_decode((string)$raw, true);
        if (is_array($data)) {
            $base = array_replace_recursive($base, $data);
        }
    }
    if (function_exists('shell_exec')) {
        $enabled = trim((string)@shell_exec('systemctl is-enabled rac-health.timer 2>/dev/null'));
        $active = trim((string)@shell_exec('systemctl is-active rac-health.timer 2>/dev/null'));
        $serviceActive = trim((string)@shell_exec('systemctl is-active rac-health.service 2>/dev/null'));
        $next = trim((string)@shell_exec("systemctl list-timers --all --no-pager rac-health.timer 2>/dev/null | awk 'NR==2{print $1\" \"$2\" \"$3\" \"$4\" \"$5}'"));
        $last = trim((string)@shell_exec("systemctl list-timers --all --no-pager rac-health.timer 2>/dev/null | awk 'NR==2{print $7\" \"$8\" \"$9\" \"$10\" \"$11}'"));
        $journalRaw = trim((string)@shell_exec('journalctl -u rac-health.service -n 8 --no-pager 2>/dev/null'));
        $base['timer'] = [
            'enabled' => $enabled === 'enabled',
            'active' => $active === 'active',
            'next' => $next === '-' ? '' : $next,
            'last' => $last === '-' ? '' : $last,
        ];
        $base['service'] = [
            'active' => $serviceActive === 'active',
            'journal' => $journalRaw === '' ? [] : preg_split("/\r\n|\n|\r/", $journalRaw),
        ];
    }
    $checks = is_array($base['output'] ?? null) ? count($base['output']) : 0;
    $okChecks = 0;
    foreach (($base['output'] ?? []) as $line) {
        if (strpos((string)$line, 'OK: ') === 0) {
            $okChecks++;
        }
    }
    $base['summary'] = [
        'checks' => $checks,
        'okChecks' => $okChecks,
        'failedChecks' => max(0, $checks - $okChecks - (in_array('SMOKE COMPLETO', $base['output'] ?? [], true) ? 1 : 0)),
    ];
    return $base;
}

function aff_lead_financial_flow(PDO $pdo, string $leadId): array {
    $st = $pdo->prepare("SELECT l.*, p.name AS product_name, o.owner_code, o.owner_name, g.name AS gestor_name
        FROM affiliate_leads l
        JOIN affiliate_products p ON p.id=l.product_id
        JOIN affiliate_owners o ON o.id=l.owner_id
        JOIN affiliate_gestores g ON g.id=l.gestor_id
        WHERE l.id=? LIMIT 1");
    $st->execute([$leadId]);
    $lead = $st->fetch();
    if (!$lead) {
        throw new RuntimeException('lead_not_found');
    }
    $mv = $pdo->prepare("SELECT movement_type, amount, delta_available, delta_blocked, note, created_at
        FROM affiliate_wallet_movements
        WHERE lead_id=?
        ORDER BY id ASC");
    $mv->execute([$leadId]);
    $movements = [];
    foreach ($mv->fetchAll() as $row) {
        $movements[] = [
            'movementType' => (string)$row['movement_type'],
            'amount' => round((float)$row['amount'], 2),
            'deltaAvailable' => round((float)$row['delta_available'], 2),
            'deltaBlocked' => round((float)$row['delta_blocked'], 2),
            'note' => (string)$row['note'],
            'createdAt' => (string)$row['created_at'],
        ];
    }
    return [
        'id' => (string)$lead['id'],
        'traceCode' => (string)$lead['trace_code'],
        'status' => (string)$lead['status'],
        'owner' => trim((string)$lead['owner_code'] . ' · ' . (string)$lead['owner_name']),
        'gestor' => trim((string)$lead['gestor_id'] . ' · ' . (string)$lead['gestor_name']),
        'product' => (string)$lead['product_name'],
        'clientName' => (string)($lead['client_name'] ?? ''),
        'clientPhone' => (string)($lead['client_phone'] ?? ''),
        'commission' => round((float)$lead['commission'], 2),
        'lockedCommission' => round((float)$lead['locked_commission'], 2),
        'gestorShare' => round((float)$lead['gestor_share'], 2),
        'platformShare' => round((float)$lead['platform_share'], 2),
        'triggeredAt' => (string)($lead['triggered_at'] ?? ''),
        'contactOpenedAt' => (string)($lead['contact_opened_at'] ?? ''),
        'soldAt' => (string)($lead['sold_at'] ?? ''),
        'noSaleAt' => (string)($lead['no_sale_at'] ?? ''),
        'movements' => $movements,
    ];
}

function aff_market_insights(PDO $pdo): array {
    return [
        'zones' => $pdo->query("SELECT geo_zone AS zone, COUNT(*) AS owners, ROUND(AVG(reputation_score),2) AS reputation FROM affiliate_owners WHERE geo_zone IS NOT NULL AND geo_zone<>'' GROUP BY geo_zone ORDER BY owners DESC, zone ASC LIMIT 10")->fetchAll(),
        'categories' => $pdo->query("SELECT category, SUM(clicks) AS clicks, SUM(leads) AS leads, SUM(sales) AS sales FROM affiliate_products WHERE active=1 GROUP BY category ORDER BY clicks DESC, category ASC LIMIT 8")->fetchAll(),
        'plans' => $pdo->query("SELECT subscription_plan AS plan, COUNT(*) AS total FROM affiliate_owners GROUP BY subscription_plan ORDER BY total DESC, plan ASC")->fetchAll(),
    ];
}

function aff_wallet_topups(PDO $pdo): array {
    $st = $pdo->query("SELECT t.id, o.owner_code AS ownerCode, o.owner_name AS ownerName, t.amount, t.payment_method AS paymentMethod, t.reference_code AS referenceCode, t.status, t.note, t.created_at AS createdAt, t.reviewed_at AS reviewedAt, t.reviewed_by AS reviewedBy
        FROM affiliate_wallet_topups t
        JOIN affiliate_owners o ON o.id=t.owner_id
        ORDER BY FIELD(t.status,'pending','approved','rejected'), t.id DESC
        LIMIT 100");
    return $st->fetchAll();
}

function aff_billing_charges(PDO $pdo): array {
    $st = $pdo->query("SELECT c.id, o.owner_code AS ownerCode, o.owner_name AS ownerName, c.charge_type AS chargeType, c.amount, c.reference_code AS referenceCode, c.status, c.note, c.due_at AS dueAt, c.created_at AS createdAt, c.paid_at AS paidAt, c.settled_by AS settledBy
        FROM affiliate_billing_charges c
        JOIN affiliate_owners o ON o.id=c.owner_id
        ORDER BY FIELD(c.status,'pending','paid','cancelled'), c.id DESC
        LIMIT 120");
    return $st->fetchAll();
}

function aff_owner_billing_charges(PDO $pdo, int $ownerId): array {
    $st = $pdo->prepare("SELECT c.id, o.owner_code AS ownerCode, o.owner_name AS ownerName, c.charge_type AS chargeType, c.amount, c.reference_code AS referenceCode, c.status, c.note, c.due_at AS dueAt, c.created_at AS createdAt, c.paid_at AS paidAt, c.settled_by AS settledBy
        FROM affiliate_billing_charges c
        JOIN affiliate_owners o ON o.id=c.owner_id
        WHERE c.owner_id=?
        ORDER BY c.id DESC
        LIMIT 120");
    $st->execute([$ownerId]);
    return $st->fetchAll();
}

function aff_payment_reconciliations(PDO $pdo): array {
    $st = $pdo->query("SELECT id, owner_id AS ownerId, payment_channel AS paymentChannel, reference_code AS referenceCode, amount, target_type AS targetType, target_id AS targetId, status, note, created_at AS createdAt
        FROM affiliate_payment_reconciliations
        ORDER BY id DESC
        LIMIT 120");
    return $st->fetchAll();
}

function aff_external_payments(PDO $pdo): array {
    $st = $pdo->query("SELECT id, payment_channel AS paymentChannel, reference_code AS referenceCode, amount, payer_name AS payerName, source_type AS sourceType, matched_target_type AS matchedTargetType, matched_target_id AS matchedTargetId, status, note, paid_at AS paidAt, created_at AS createdAt
        FROM affiliate_external_payments
        ORDER BY FIELD(status,'pending','matched','unmatched','duplicate'), id DESC
        LIMIT 150");
    return $st->fetchAll();
}

function aff_store_external_payment(PDO $pdo, array $payload): array {
    $channel = substr(trim((string)($payload['payment_channel'] ?? 'Transfermóvil')), 0, 40);
    $reference = substr(trim((string)($payload['reference_code'] ?? '')), 0, 120);
    $amount = round((float)($payload['amount'] ?? 0), 2);
    $payer = substr(trim((string)($payload['payer_name'] ?? '')), 0, 120);
    $sourceType = in_array((string)($payload['source_type'] ?? 'extract'), ['extract', 'gateway'], true) ? (string)$payload['source_type'] : 'extract';
    $note = substr(trim((string)($payload['note'] ?? '')), 0, 255);
    $paidAt = trim((string)($payload['paid_at'] ?? ''));
    if ($reference === '' || $amount <= 0) {
        throw new InvalidArgumentException('external_payment_fields_required');
    }
    $exists = $pdo->prepare("SELECT id, status, matched_target_type AS matchedTargetType, matched_target_id AS matchedTargetId FROM affiliate_external_payments WHERE reference_code=? AND amount=? AND payment_channel=? LIMIT 1");
    $exists->execute([$reference, $amount, $channel]);
    $row = $exists->fetch();
    if ($row) {
        return [
            'id' => (int)$row['id'],
            'paymentChannel' => $channel,
            'referenceCode' => $reference,
            'amount' => $amount,
            'status' => 'duplicate',
            'matchedTargetType' => $row['matchedTargetType'] ?? null,
            'matchedTargetId' => $row['matchedTargetId'] ?? null,
        ];
    }
    $st = $pdo->prepare("INSERT INTO affiliate_external_payments (payment_channel, reference_code, amount, payer_name, raw_payload_json, source_type, status, note, paid_at, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$channel, $reference, $amount, $payer, json_encode($payload, JSON_UNESCAPED_UNICODE), $sourceType, 'pending', $note, $paidAt !== '' ? $paidAt : null, aff_now()]);
    return [
        'id' => (int)$pdo->lastInsertId(),
        'paymentChannel' => $channel,
        'referenceCode' => $reference,
        'amount' => $amount,
        'status' => 'pending',
        'matchedTargetType' => null,
        'matchedTargetId' => null,
    ];
}

function aff_generate_billing_charges(PDO $pdo): array {
    $created = ['subscription' => 0, 'advertising' => 0];
    $owners = $pdo->query("SELECT id, owner_code, monthly_fee, advertising_budget, subscription_due_at, ads_active, status FROM affiliate_owners WHERE status='active'")->fetchAll();
    $find = $pdo->prepare("SELECT id FROM affiliate_billing_charges WHERE owner_id=? AND charge_type=? AND status='pending' LIMIT 1");
    $insert = $pdo->prepare("INSERT INTO affiliate_billing_charges (owner_id, charge_type, amount, reference_code, status, note, due_at, created_at) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($owners as $owner) {
        $ownerId = (int)$owner['id'];
        $ownerCode = (string)$owner['owner_code'];
        $dueAt = (string)($owner['subscription_due_at'] ?? '');
        $monthlyFee = round((float)($owner['monthly_fee'] ?? 0), 2);
        if ($monthlyFee > 0 && $dueAt !== '' && strtotime($dueAt) <= time()) {
            $find->execute([$ownerId, 'subscription']);
            if (!$find->fetchColumn()) {
                $ref = 'RAC-SUB-' . preg_replace('/[^A-Z0-9]/', '', strtoupper($ownerCode)) . '-' . date('Ym');
                $insert->execute([$ownerId, 'subscription', $monthlyFee, $ref, 'pending', 'Cuota mensual RAC', $dueAt, aff_now()]);
                $created['subscription']++;
            }
        }
        $adsBudget = round((float)($owner['advertising_budget'] ?? 0), 2);
        if ((int)($owner['ads_active'] ?? 0) === 1 && $adsBudget > 0) {
            $find->execute([$ownerId, 'advertising']);
            if (!$find->fetchColumn()) {
                $ref = 'RAC-ADS-' . preg_replace('/[^A-Z0-9]/', '', strtoupper($ownerCode)) . '-' . date('Ym');
                $insert->execute([$ownerId, 'advertising', $adsBudget, $ref, 'pending', 'Publicidad interna RAC', aff_now(), aff_now()]);
                $created['advertising']++;
            }
        }
    }
    return $created;
}

function aff_create_billing_charge(PDO $pdo, array $input): array {
    $ownerId = (int)($input['owner_id'] ?? 0);
    $chargeType = in_array((string)($input['charge_type'] ?? ''), ['subscription', 'advertising'], true) ? (string)$input['charge_type'] : '';
    $amount = round((float)($input['amount'] ?? 0), 2);
    $reference = substr(trim((string)($input['reference_code'] ?? '')), 0, 120);
    $note = substr(trim((string)($input['note'] ?? '')), 0, 255);
    $dueAt = trim((string)($input['due_at'] ?? ''));
    if ($ownerId <= 0 || $chargeType === '' || $amount <= 0 || $reference === '') {
        throw new InvalidArgumentException('billing_charge_fields_required');
    }
    $st = $pdo->prepare("INSERT INTO affiliate_billing_charges (owner_id, charge_type, amount, reference_code, status, note, due_at, created_at) VALUES (?,?,?,?,?,?,?,?)");
    $st->execute([$ownerId, $chargeType, $amount, $reference, 'pending', $note, $dueAt !== '' ? $dueAt : null, aff_now()]);
    aff_record_audit($pdo, [
        'owner_id' => $ownerId,
        'event_type' => 'billing_charge_created',
        'severity' => 'info',
        'message' => 'Cargo financiero RAC creado',
        'context' => ['charge_type' => $chargeType, 'amount' => $amount, 'reference_code' => $reference],
    ]);
    foreach (aff_billing_charges($pdo) as $row) {
        if ((int)$row['id'] === (int)$pdo->lastInsertId()) return $row;
    }
    throw new RuntimeException('billing_charge_not_found');
}

function aff_reconcile_payment_reference(PDO $pdo, array $input): array {
    $channel = substr(trim((string)($input['payment_channel'] ?? 'Transfermóvil')), 0, 40);
    $reference = substr(trim((string)($input['reference_code'] ?? '')), 0, 120);
    $amount = round((float)($input['amount'] ?? 0), 2);
    $note = substr(trim((string)($input['note'] ?? '')), 0, 255);
    if ($reference === '' || $amount <= 0) {
        throw new InvalidArgumentException('reference_and_amount_required');
    }

    $topup = $pdo->prepare("SELECT * FROM affiliate_wallet_topups WHERE reference_code=? AND status='pending' ORDER BY id DESC LIMIT 1");
    $topup->execute([$reference]);
    $topupRow = $topup->fetch();
    if ($topupRow) {
        aff_review_wallet_topup($pdo, (int)$topupRow['id'], 'approved', $note !== '' ? $note : 'Conciliado por referencia externa');
        $ins = $pdo->prepare("INSERT INTO affiliate_payment_reconciliations (owner_id, payment_channel, reference_code, amount, target_type, target_id, status, note, created_at) VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->execute([(int)$topupRow['owner_id'], $channel, $reference, $amount, 'wallet_topup', (int)$topupRow['id'], 'matched', $note, aff_now()]);
        $upExt = $pdo->prepare("UPDATE affiliate_external_payments SET matched_target_type='wallet_topup', matched_target_id=?, status='matched', note=COALESCE(NULLIF(?, ''), note) WHERE reference_code=? AND amount=? AND payment_channel=?");
        $upExt->execute([(int)$topupRow['id'], $note, $reference, $amount, $channel]);
        return ['targetType' => 'wallet_topup', 'targetId' => (int)$topupRow['id'], 'referenceCode' => $reference, 'amount' => $amount];
    }

    $charge = $pdo->prepare("SELECT * FROM affiliate_billing_charges WHERE reference_code=? AND status='pending' ORDER BY id DESC LIMIT 1");
    $charge->execute([$reference]);
    $chargeRow = $charge->fetch();
    if ($chargeRow) {
        $up = $pdo->prepare("UPDATE affiliate_billing_charges SET status='paid', paid_at=?, settled_by=? WHERE id=?");
        $up->execute([aff_now(), aff_auth_context()['username'] ?: 'admin', (int)$chargeRow['id']]);
        $ins = $pdo->prepare("INSERT INTO affiliate_payment_reconciliations (owner_id, payment_channel, reference_code, amount, target_type, target_id, status, note, created_at) VALUES (?,?,?,?,?,?,?,?,?)");
        $ins->execute([(int)$chargeRow['owner_id'], $channel, $reference, $amount, 'billing_charge', (int)$chargeRow['id'], 'matched', $note, aff_now()]);
        $upExt = $pdo->prepare("UPDATE affiliate_external_payments SET owner_id=?, matched_target_type='billing_charge', matched_target_id=?, status='matched', note=COALESCE(NULLIF(?, ''), note) WHERE reference_code=? AND amount=? AND payment_channel=?");
        $upExt->execute([(int)$chargeRow['owner_id'], (int)$chargeRow['id'], $note, $reference, $amount, $channel]);
        aff_record_audit($pdo, [
            'owner_id' => (int)$chargeRow['owner_id'],
            'event_type' => 'billing_charge_paid',
            'severity' => 'info',
            'message' => 'Cargo RAC conciliado por referencia externa',
            'context' => ['charge_id' => (int)$chargeRow['id'], 'reference_code' => $reference, 'amount' => $amount],
        ]);
        return ['targetType' => 'billing_charge', 'targetId' => (int)$chargeRow['id'], 'referenceCode' => $reference, 'amount' => $amount];
    }

    $ins = $pdo->prepare("INSERT INTO affiliate_payment_reconciliations (owner_id, payment_channel, reference_code, amount, target_type, target_id, status, note, created_at) VALUES (?,?,?,?,?,?,?,?,?)");
    $ins->execute([null, $channel, $reference, $amount, 'unmatched', 0, 'unmatched', $note !== '' ? $note : 'Referencia sin match', aff_now()]);
    $upExt = $pdo->prepare("UPDATE affiliate_external_payments SET status='unmatched', note=COALESCE(NULLIF(?, ''), note) WHERE reference_code=? AND amount=? AND payment_channel=?");
    $upExt->execute([$note !== '' ? $note : 'Referencia sin match', $reference, $amount, $channel]);
    throw new RuntimeException('reference_not_matched');
}

function aff_import_payment_extract(PDO $pdo, array $input): array {
    $channel = substr(trim((string)($input['payment_channel'] ?? 'Transfermóvil')), 0, 40);
    $csv = (string)($input['csv_text'] ?? '');
    if (trim($csv) === '') {
        throw new InvalidArgumentException('csv_text_required');
    }
    $lines = preg_split("/\r\n|\n|\r/", trim($csv));
    $inserted = 0;
    $duplicates = 0;
    foreach ($lines as $idx => $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        if ($idx === 0 && preg_match('/reference/i', $line)) {
            continue;
        }
        $parts = str_getcsv($line);
        if (count($parts) < 2) {
            continue;
        }
        $payload = [
            'payment_channel' => $channel,
            'reference_code' => trim((string)($parts[0] ?? '')),
            'amount' => (float)str_replace(',', '.', trim((string)($parts[1] ?? '0'))),
            'payer_name' => trim((string)($parts[2] ?? '')),
            'paid_at' => trim((string)($parts[3] ?? '')),
            'note' => trim((string)($parts[4] ?? '')),
            'source_type' => 'extract',
        ];
        $row = aff_store_external_payment($pdo, $payload);
        if (($row['status'] ?? '') === 'duplicate') {
            $duplicates++;
        } else {
            $inserted++;
        }
    }
    aff_record_audit($pdo, [
        'event_type' => 'payment_extract_imported',
        'severity' => 'info',
        'message' => 'Extracto RAC importado',
        'context' => ['inserted' => $inserted, 'duplicates' => $duplicates],
    ]);
    return ['inserted' => $inserted, 'duplicates' => $duplicates];
}

function aff_auto_reconcile_pending_imports(PDO $pdo): array {
    $st = $pdo->query("SELECT id, payment_channel, reference_code, amount, note FROM affiliate_external_payments WHERE status='pending' ORDER BY id ASC LIMIT 200");
    $matched = 0;
    $unmatched = 0;
    foreach ($st->fetchAll() as $row) {
        try {
            aff_reconcile_payment_reference($pdo, [
                'payment_channel' => (string)$row['payment_channel'],
                'reference_code' => (string)$row['reference_code'],
                'amount' => (float)$row['amount'],
                'note' => (string)($row['note'] ?? ''),
            ]);
            $matched++;
        } catch (Throwable $e) {
            $unmatched++;
        }
    }
    aff_record_audit($pdo, [
        'event_type' => 'payment_batch_reconciled',
        'severity' => 'info',
        'message' => 'Conciliación automática por lote ejecutada',
        'context' => ['matched' => $matched, 'unmatched' => $unmatched],
    ]);
    return ['matched' => $matched, 'unmatched' => $unmatched];
}

function aff_payment_webhook_token(): string {
    return trim((string)(getenv('AFFILIATE_PAYMENT_WEBHOOK_TOKEN') ?: ''));
}

function aff_owner_wallet_topups(PDO $pdo, int $ownerId): array {
    $st = $pdo->prepare("SELECT t.id, o.owner_code AS ownerCode, o.owner_name AS ownerName, t.amount, t.payment_method AS paymentMethod, t.reference_code AS referenceCode, t.status, t.note, t.created_at AS createdAt, t.reviewed_at AS reviewedAt, t.reviewed_by AS reviewedBy
        FROM affiliate_wallet_topups t
        JOIN affiliate_owners o ON o.id=t.owner_id
        WHERE t.owner_id=?
        ORDER BY t.id DESC
        LIMIT 100");
    $st->execute([$ownerId]);
    return $st->fetchAll();
}

function aff_recent_audit_events(PDO $pdo): array {
    $st = $pdo->query("SELECT event_type AS eventType, severity, message, created_at AS createdAt FROM affiliate_audit_events ORDER BY id DESC LIMIT 40");
    return $st->fetchAll();
}

function aff_access_audit_events(PDO $pdo): array {
    $st = $pdo->query("SELECT event_type AS eventType, severity, message, created_at AS createdAt, context_json AS contextJson
        FROM affiliate_audit_events
        WHERE event_type IN ('affiliate_login','affiliate_login_failed','affiliate_login_locked','affiliate_logout','affiliate_session_expired','affiliate_session_revoked','affiliate_user_password_changed','affiliate_user_password_reset','affiliate_user_deleted','affiliate_user_upserted')
        ORDER BY id DESC
        LIMIT 120");
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $ctx = json_decode((string)($row['contextJson'] ?? ''), true);
        $rows[] = [
            'eventType' => (string)$row['eventType'],
            'severity' => (string)$row['severity'],
            'message' => (string)$row['message'],
            'createdAt' => (string)$row['createdAt'],
            'context' => is_array($ctx) ? $ctx : [],
        ];
    }
    return $rows;
}

function aff_active_sessions(PDO $pdo): array {
    $st = $pdo->query("SELECT s.id, s.session_id AS sessionId, s.role, s.ip_address AS ipAddress, s.user_agent AS userAgent, s.created_at AS createdAt, s.last_seen_at AS lastSeenAt, s.expires_at AS expiresAt, u.username, u.display_name AS displayName
        FROM affiliate_user_sessions s
        JOIN affiliate_users u ON u.id=s.user_id
        WHERE s.logged_out_at IS NULL AND s.revoked_at IS NULL AND s.expires_at >= NOW()
        ORDER BY s.last_seen_at DESC
        LIMIT 50");
    return $st->fetchAll();
}

function aff_recent_lockouts(PDO $pdo): array {
    $windowStart = date('Y-m-d H:i:s', time() - 86400);
    $st = $pdo->prepare("SELECT username, MAX(attempted_at) AS lastAttempt, COUNT(*) AS failedCount
        FROM affiliate_login_attempts
        WHERE success=0 AND attempted_at >= ?
        GROUP BY username
        HAVING COUNT(*) >= ?
        ORDER BY MAX(attempted_at) DESC
        LIMIT 30");
    $st->execute([$windowStart, AFF_LOCK_THRESHOLD]);
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $lastAttempt = strtotime((string)$row['lastAttempt']);
        $lockedUntil = $lastAttempt ? date('Y-m-d H:i:s', $lastAttempt + AFF_LOCK_DURATION_SECONDS) : '';
        $rows[] = [
            'username' => (string)$row['username'],
            'failedCount' => (int)$row['failedCount'],
            'lastAttempt' => (string)$row['lastAttempt'],
            'lockedUntil' => $lockedUntil,
            'active' => $lockedUntil !== '' && strtotime($lockedUntil) > time(),
        ];
    }
    return $rows;
}

function aff_revoke_user_session(PDO $pdo, string $sessionId): array {
    $sessionId = trim($sessionId);
    if ($sessionId === '') {
        throw new InvalidArgumentException('invalid_session_id');
    }
    $st = $pdo->prepare("SELECT s.user_id, s.role, s.ip_address, s.user_agent, u.username, u.owner_id, u.gestor_id
        FROM affiliate_user_sessions s
        JOIN affiliate_users u ON u.id=s.user_id
        WHERE s.session_id=? AND s.revoked_at IS NULL AND s.logged_out_at IS NULL
        LIMIT 1");
    $st->execute([$sessionId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('session_not_found');
    }
    aff_close_session_record($pdo, $sessionId, 'revoked_at');
    aff_record_audit($pdo, [
        'owner_id' => $row['owner_id'] !== null ? (int)$row['owner_id'] : null,
        'gestor_id' => $row['gestor_id'] !== null ? (string)$row['gestor_id'] : null,
        'event_type' => 'affiliate_session_revoked',
        'severity' => 'warning',
        'message' => 'Sesión RAC revocada por administrador',
        'context' => [
            'username' => (string)$row['username'],
            'role' => (string)$row['role'],
            'ip' => (string)($row['ip_address'] ?? ''),
            'user_agent' => (string)($row['user_agent'] ?? ''),
            'session_id' => $sessionId,
        ],
    ]);
    return [
        'sessionId' => $sessionId,
        'username' => (string)$row['username'],
        'role' => (string)$row['role'],
        'revokedAt' => aff_now(),
    ];
}

function aff_subscription_metrics(PDO $pdo): array {
    return [
        'expectedMrr' => (float)$pdo->query("SELECT COALESCE(SUM(monthly_fee),0) FROM affiliate_owners WHERE status='active'")->fetchColumn(),
        'overdueOwners' => (int)$pdo->query("SELECT COUNT(*) FROM affiliate_owners WHERE subscription_due_at IS NOT NULL AND subscription_due_at < NOW() AND status='active'")->fetchColumn(),
        'managedOwners' => (int)$pdo->query("SELECT COUNT(*) FROM affiliate_owners WHERE managed_service=1 AND status='active'")->fetchColumn(),
        'adsActiveOwners' => (int)$pdo->query("SELECT COUNT(*) FROM affiliate_owners WHERE ads_active=1 AND status='active'")->fetchColumn(),
        'pendingTopups' => (int)$pdo->query("SELECT COUNT(*) FROM affiliate_wallet_topups WHERE status='pending'")->fetchColumn(),
    ];
}

function aff_sponsored_products(PDO $pdo): array {
    $st = $pdo->query("SELECT p.id, p.name, p.sponsor_rank AS sponsorRank, p.clicks, p.leads, p.sales, o.owner_code AS ownerCode, o.owner_name AS ownerName
        FROM affiliate_products p
        JOIN affiliate_owners o ON o.id=p.owner_id
        WHERE p.active=1 AND (p.is_featured=1 OR p.sponsor_rank > 0 OR o.ads_active=1)
        ORDER BY p.sponsor_rank DESC, p.clicks DESC, p.sales DESC
        LIMIT 12");
    return $st->fetchAll();
}

function aff_advanced_audit_signals(PDO $pdo): array {
    return [
        'owners' => $pdo->query("SELECT o.owner_code AS ownerCode, o.owner_name AS ownerName, o.fraud_risk AS fraudRisk, ROUND(COALESCE(SUM(CASE WHEN l.status='sold' THEN 1 ELSE 0 END) / NULLIF(COUNT(l.id),0),0) * 100,2) AS conversionRate, COUNT(l.id) AS leads, SUM(CASE WHEN l.status='no_sale' THEN 1 ELSE 0 END) AS noSaleCount
            FROM affiliate_owners o
            LEFT JOIN affiliate_leads l ON l.owner_id=o.id
            GROUP BY o.id, o.owner_code, o.owner_name, o.fraud_risk
            HAVING leads >= 4
            ORDER BY FIELD(o.fraud_risk,'ALTO','MEDIO','BAJO'), noSaleCount DESC, leads DESC
            LIMIT 12")->fetchAll(),
        'gestores' => $pdo->query("SELECT g.id, g.name, g.reputation_score AS reputationScore, COUNT(l.id) AS leads, SUM(CASE WHEN l.status='sold' THEN 1 ELSE 0 END) AS soldCount, SUM(CASE WHEN l.status='no_sale' THEN 1 ELSE 0 END) AS noSaleCount
            FROM affiliate_gestores g
            LEFT JOIN affiliate_leads l ON l.gestor_id=g.id
            GROUP BY g.id, g.name, g.reputation_score
            HAVING leads >= 3
            ORDER BY noSaleCount DESC, leads DESC, g.reputation_score ASC
            LIMIT 12")->fetchAll(),
    ];
}

function aff_owner_admin_list(PDO $pdo): array {
    $st = $pdo->query("SELECT id, owner_code AS ownerCode, owner_name AS ownerName, phone, whatsapp_number AS whatsappNumber, geo_zone AS geoZone, subscription_plan AS subscriptionPlan, managed_service AS managedService, monthly_fee AS monthlyFee, subscription_due_at AS subscriptionDueAt, advertising_budget AS advertisingBudget, ads_active AS adsActive, available_balance AS availableBalance, blocked_balance AS blockedBalance, total_balance AS totalBalance, status, reputation_score AS reputationScore, fraud_risk AS fraudRisk
        FROM affiliate_owners ORDER BY owner_code ASC");
    return $st->fetchAll();
}

function aff_gestor_admin_list(PDO $pdo): array {
    $st = $pdo->query("SELECT id, name, phone, telegram_chat_id AS telegramChatId, masked_code AS maskedCode, earnings, links, conversions, rating, reputation_score AS reputationScore, status
        FROM affiliate_gestores ORDER BY id ASC");
    return $st->fetchAll();
}

function aff_user_admin_list(PDO $pdo): array {
    $st = $pdo->query("SELECT u.id, u.username, u.role, u.display_name AS displayName, u.owner_id AS ownerId, o.owner_code AS ownerCode, u.gestor_id AS gestorId, g.name AS gestorName, u.status, u.created_at AS createdAt
        FROM affiliate_users u
        LEFT JOIN affiliate_owners o ON o.id=u.owner_id
        LEFT JOIN affiliate_gestores g ON g.id=u.gestor_id
        ORDER BY FIELD(u.role,'admin','owner','gestor'), u.username ASC");
    return $st->fetchAll();
}

function aff_user_role_summary(PDO $pdo): array {
    $st = $pdo->query("SELECT role, status, COUNT(*) AS total FROM affiliate_users GROUP BY role, status ORDER BY role, status");
    $summary = [
        'admin' => ['active' => 0, 'suspended' => 0, 'total' => 0],
        'owner' => ['active' => 0, 'suspended' => 0, 'total' => 0],
        'gestor' => ['active' => 0, 'suspended' => 0, 'total' => 0],
    ];
    foreach ($st->fetchAll() as $row) {
        $role = (string)$row['role'];
        $status = (string)$row['status'];
        $total = (int)$row['total'];
        if (!isset($summary[$role])) {
            $summary[$role] = ['active' => 0, 'suspended' => 0, 'total' => 0];
        }
        if (!isset($summary[$role][$status])) {
            $summary[$role][$status] = 0;
        }
        $summary[$role][$status] += $total;
        $summary[$role]['total'] += $total;
    }
    return $summary;
}

function aff_upsert_owner(PDO $pdo, array $input): array {
    $id = (int)($input['id'] ?? 0);
    $ownerCode = substr(trim((string)($input['owner_code'] ?? '')), 0, 20);
    $ownerName = substr(trim((string)($input['owner_name'] ?? '')), 0, 120);
    if ($ownerCode === '' || $ownerName === '') {
        throw new InvalidArgumentException('owner_code_and_name_required');
    }
    $phone = substr(trim((string)($input['phone'] ?? '')), 0, 40);
    $whatsapp = substr(trim((string)($input['whatsapp_number'] ?? '')), 0, 40);
    $zone = substr(trim((string)($input['geo_zone'] ?? '')), 0, 120);
    $plan = substr(trim((string)($input['subscription_plan'] ?? 'basic')), 0, 40) ?: 'basic';
    $managed = !empty($input['managed_service']) ? 1 : 0;
    $monthlyFee = round((float)($input['monthly_fee'] ?? 0), 2);
    $dueAt = trim((string)($input['subscription_due_at'] ?? ''));
    $adsBudget = round((float)($input['advertising_budget'] ?? 0), 2);
    $adsActive = !empty($input['ads_active']) ? 1 : 0;
    $status = in_array((string)($input['status'] ?? 'active'), ['active','suspended'], true) ? (string)$input['status'] : 'active';
    if ($id > 0) {
        $st = $pdo->prepare("UPDATE affiliate_owners SET owner_code=?, owner_name=?, phone=?, whatsapp_number=?, geo_zone=?, subscription_plan=?, managed_service=?, monthly_fee=?, subscription_due_at=?, advertising_budget=?, ads_active=?, status=? WHERE id=?");
        $st->execute([$ownerCode,$ownerName,$phone,$whatsapp,$zone,$plan,$managed,$monthlyFee,$dueAt !== '' ? $dueAt : null,$adsBudget,$adsActive,$status,$id]);
    } else {
        $st = $pdo->prepare("INSERT INTO affiliate_owners (owner_code, owner_name, phone, whatsapp_number, geo_zone, subscription_plan, managed_service, reputation_score, fraud_risk, available_balance, blocked_balance, total_balance, status, monthly_fee, subscription_due_at, advertising_budget, ads_active, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $st->execute([$ownerCode,$ownerName,$phone,$whatsapp,$zone,$plan,$managed,80,'BAJO',0,0,0,$status,$monthlyFee,$dueAt !== '' ? $dueAt : null,$adsBudget,$adsActive,aff_now()]);
        $id = (int)$pdo->lastInsertId();
    }
    aff_record_audit($pdo, [
        'owner_id' => $id,
        'event_type' => 'owner_upserted',
        'severity' => 'info',
        'message' => 'Dueño RAC guardado desde panel admin',
        'context' => ['owner_code' => $ownerCode, 'plan' => $plan, 'ads_active' => $adsActive],
    ]);
    foreach (aff_owner_admin_list($pdo) as $row) {
        if ((int)$row['id'] === $id) return $row;
    }
    throw new RuntimeException('owner_not_found');
}

function aff_upsert_gestor(PDO $pdo, array $input): array {
    $id = substr(trim((string)($input['id'] ?? '')), 0, 20);
    $name = substr(trim((string)($input['name'] ?? '')), 0, 120);
    if ($id === '' || $name === '') {
        throw new InvalidArgumentException('gestor_id_and_name_required');
    }
    $phone = substr(trim((string)($input['phone'] ?? '')), 0, 40);
    $chatId = substr(trim((string)($input['telegram_chat_id'] ?? '')), 0, 80);
    $masked = substr(trim((string)($input['masked_code'] ?? ('GEN-' . strtoupper($id)))), 0, 60);
    $status = in_array((string)($input['status'] ?? 'active'), ['active','suspended'], true) ? (string)$input['status'] : 'active';
    $st = $pdo->prepare("INSERT INTO affiliate_gestores (id, name, phone, telegram_chat_id, masked_code, reputation_score, earnings, links, conversions, rating, status, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE name=VALUES(name), phone=VALUES(phone), telegram_chat_id=VALUES(telegram_chat_id), masked_code=VALUES(masked_code), status=VALUES(status)");
    $st->execute([$id,$name,$phone,$chatId,$masked,80,0,0,0,5.0,$status,aff_now()]);
    aff_record_audit($pdo, [
        'gestor_id' => $id,
        'event_type' => 'gestor_upserted',
        'severity' => 'info',
        'message' => 'Gestor RAC guardado desde panel admin',
        'context' => ['masked_code' => $masked, 'status' => $status],
    ]);
    foreach (aff_gestor_admin_list($pdo) as $row) {
        if ((string)$row['id'] === $id) return $row;
    }
    throw new RuntimeException('gestor_not_found');
}

function aff_upsert_user(PDO $pdo, array $input): array {
    $id = (int)($input['id'] ?? 0);
    $username = substr(trim((string)($input['username'] ?? '')), 0, 80);
    $role = (string)($input['role'] ?? '');
    $displayName = substr(trim((string)($input['display_name'] ?? '')), 0, 120);
    $ownerId = !empty($input['owner_id']) ? (int)$input['owner_id'] : null;
    $gestorId = trim((string)($input['gestor_id'] ?? '')) ?: null;
    $status = in_array((string)($input['status'] ?? 'active'), ['active','suspended'], true) ? (string)$input['status'] : 'active';
    $password = (string)($input['password'] ?? '');
    if ($username === '' || $displayName === '' || !in_array($role, ['admin','owner','gestor'], true)) {
        throw new InvalidArgumentException('user_fields_required');
    }
    if ($role === 'owner' && !$ownerId) {
        throw new InvalidArgumentException('owner_user_requires_owner');
    }
    if ($role === 'gestor' && !$gestorId) {
        throw new InvalidArgumentException('gestor_user_requires_gestor');
    }
    if ($role !== 'owner') {
        $ownerId = null;
    }
    if ($role !== 'gestor') {
        $gestorId = null;
    }
    if ($id > 0) {
        $st = $pdo->prepare("UPDATE affiliate_users SET username=?, role=?, display_name=?, owner_id=?, gestor_id=?, status=? WHERE id=?");
        $st->execute([$username, $role, $displayName, $ownerId, $gestorId, $status, $id]);
        if ($password !== '') {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $pwd = $pdo->prepare("UPDATE affiliate_users SET password_hash=? WHERE id=?");
            $pwd->execute([$hash, $id]);
        }
    } else {
        if ($password === '') {
            throw new InvalidArgumentException('password_required');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO affiliate_users (username, password_hash, role, display_name, owner_id, gestor_id, status, created_at) VALUES (?,?,?,?,?,?,?,?)");
        $st->execute([$username, $hash, $role, $displayName, $ownerId, $gestorId, $status, aff_now()]);
        $id = (int)$pdo->lastInsertId();
    }
    aff_record_audit($pdo, [
        'owner_id' => $ownerId,
        'gestor_id' => $gestorId,
        'event_type' => 'affiliate_user_upserted',
        'severity' => 'info',
        'message' => 'Usuario RAC guardado desde admin',
        'context' => ['username' => $username, 'role' => $role, 'status' => $status],
    ]);
    foreach (aff_user_admin_list($pdo) as $row) {
        if ((int)$row['id'] === $id) return $row;
    }
    throw new RuntimeException('user_not_found');
}

function aff_admin_reset_user_password(PDO $pdo, int $id, string $newPassword): array {
    $newPassword = trim($newPassword);
    if ($id <= 0 || strlen($newPassword) < 6) {
        throw new InvalidArgumentException('invalid_password_reset');
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $st = $pdo->prepare("UPDATE affiliate_users SET password_hash=? WHERE id=?");
    $st->execute([$hash, $id]);
    aff_record_audit($pdo, [
        'event_type' => 'affiliate_user_password_reset',
        'severity' => 'warning',
        'message' => 'Contraseña RAC reseteada por admin',
        'context' => ['user_id' => $id],
    ]);
    return ['id' => $id, 'reset' => true];
}

function aff_change_password(PDO $pdo, string $currentPassword, string $newPassword): array {
    $userId = aff_current_user_id();
    if (!$userId) {
        throw new RuntimeException('not_authenticated');
    }
    if (strlen(trim($newPassword)) < 6) {
        throw new InvalidArgumentException('new_password_too_short');
    }
    $st = $pdo->prepare("SELECT id, username, password_hash FROM affiliate_users WHERE id=? LIMIT 1");
    $st->execute([$userId]);
    $user = $st->fetch();
    if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
        throw new RuntimeException('invalid_current_password');
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $up = $pdo->prepare("UPDATE affiliate_users SET password_hash=? WHERE id=?");
    $up->execute([$hash, $userId]);
    aff_record_audit($pdo, [
        'event_type' => 'affiliate_user_password_changed',
        'severity' => 'info',
        'message' => 'Usuario RAC cambió su contraseña',
        'context' => ['user_id' => $userId, 'username' => (string)$user['username']],
    ]);
    return ['changed' => true];
}

function aff_delete_user(PDO $pdo, int $id): array {
    if ($id <= 0) {
        throw new InvalidArgumentException('invalid_user_id');
    }
    $currentUserId = aff_current_user_id();
    if ($currentUserId && $currentUserId === $id) {
        throw new RuntimeException('cannot_delete_current_user');
    }
    $st = $pdo->prepare("SELECT id, username, role, owner_id, gestor_id FROM affiliate_users WHERE id=? LIMIT 1");
    $st->execute([$id]);
    $user = $st->fetch();
    if (!$user) {
        throw new RuntimeException('user_not_found');
    }
    $del = $pdo->prepare("DELETE FROM affiliate_users WHERE id=?");
    $del->execute([$id]);
    aff_record_audit($pdo, [
        'owner_id' => $user['owner_id'] !== null ? (int)$user['owner_id'] : null,
        'gestor_id' => $user['gestor_id'] !== null ? (string)$user['gestor_id'] : null,
        'event_type' => 'affiliate_user_deleted',
        'severity' => 'warning',
        'message' => 'Usuario RAC eliminado desde admin',
        'context' => ['user_id' => $id, 'username' => (string)$user['username'], 'role' => (string)$user['role']],
    ]);
    return ['deleted' => true, 'id' => $id];
}

function aff_request_wallet_topup(PDO $pdo, array $input): array {
    $owner = aff_owner($pdo);
    $amount = round((float)($input['amount'] ?? 0), 2);
    $method = substr(trim((string)($input['payment_method'] ?? 'Transfermóvil')), 0, 40);
    $reference = substr(trim((string)($input['reference_code'] ?? '')), 0, 120);
    $note = substr(trim((string)($input['note'] ?? '')), 0, 255);
    if ($amount <= 0 || $reference === '') {
        throw new InvalidArgumentException('topup_amount_and_reference_required');
    }
    $st = $pdo->prepare("INSERT INTO affiliate_wallet_topups (owner_id, amount, payment_method, reference_code, status, note, created_at) VALUES (?,?,?,?,?,?,?)");
    $st->execute([(int)$owner['id'], $amount, $method, $reference, 'pending', $note, aff_now()]);
    $id = (int)$pdo->lastInsertId();
    aff_record_audit($pdo, [
        'owner_id' => (int)$owner['id'],
        'event_type' => 'wallet_topup_requested',
        'severity' => 'info',
        'message' => 'Dueño solicitó recarga de wallet',
        'context' => ['topup_id' => $id, 'amount' => $amount, 'method' => $method],
    ]);
    foreach (aff_wallet_topups($pdo) as $row) {
        if ((int)$row['id'] === $id) return $row;
    }
    throw new RuntimeException('topup_not_found');
}

function aff_review_wallet_topup(PDO $pdo, int $id, string $decision, string $note = ''): array {
    $decision = $decision === 'approved' ? 'approved' : 'rejected';
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM affiliate_wallet_topups WHERE id=? FOR UPDATE");
        $st->execute([$id]);
        $topup = $st->fetch();
        if (!$topup) {
            throw new RuntimeException('topup_not_found');
        }
        if ((string)$topup['status'] !== 'pending') {
            throw new RuntimeException('topup_already_reviewed');
        }
        $ownerSt = $pdo->prepare("SELECT * FROM affiliate_owners WHERE id=? FOR UPDATE");
        $ownerSt->execute([(int)$topup['owner_id']]);
        $owner = $ownerSt->fetch();
        if (!$owner) {
            throw new RuntimeException('owner_not_found');
        }
        if ($decision === 'approved') {
            aff_apply_wallet_move($pdo, $owner, 'manual_topup', (float)$topup['amount'], (float)$topup['amount'], 0, null, 'Recarga aprobada por administración', ['topup_id' => $id]);
        }
        $upd = $pdo->prepare("UPDATE affiliate_wallet_topups SET status=?, note=?, reviewed_at=?, reviewed_by=? WHERE id=?");
        $upd->execute([$decision, $note !== '' ? $note : (string)$topup['note'], aff_now(), 'admin', $id]);
        aff_record_audit($pdo, [
            'owner_id' => (int)$topup['owner_id'],
            'event_type' => 'wallet_topup_reviewed',
            'severity' => $decision === 'approved' ? 'info' : 'warning',
            'message' => 'Recarga de wallet ' . ($decision === 'approved' ? 'aprobada' : 'rechazada'),
            'context' => ['topup_id' => $id, 'amount' => (float)$topup['amount']],
        ]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
    foreach (aff_wallet_topups($pdo) as $row) {
        if ((int)$row['id'] === $id) return $row;
    }
    throw new RuntimeException('topup_not_found');
}

function aff_owner_product_stats(PDO $pdo, int $ownerId): array {
    $st = $pdo->prepare("SELECT
        p.id,
        p.clicks,
        p.leads,
        p.sales,
        ROUND(CASE WHEN p.leads > 0 THEN (p.sales / p.leads) * 100 ELSE 0 END, 1) AS conversionRate,
        COALESCE(SUM(CASE WHEN l.status='sold' THEN l.gestor_share ELSE 0 END), 0) AS gestorPaid,
        COALESCE(SUM(CASE WHEN l.status='sold' THEN l.platform_share ELSE 0 END), 0) AS platformEarned
        FROM affiliate_products p
        LEFT JOIN affiliate_leads l ON l.product_id = p.id
        WHERE p.owner_id=?
        GROUP BY p.id, p.clicks, p.leads, p.sales
        ORDER BY p.clicks DESC, p.sales DESC, p.name ASC");
    $st->execute([$ownerId]);
    return $st->fetchAll();
}

function aff_admin_link_rankings(PDO $pdo): array {
    $st = $pdo->query("SELECT
        t.masked_ref AS maskedRef,
        t.ref_token AS refToken,
        t.clicks,
        t.contact_opens AS contactOpens,
        t.last_opened_at AS lastOpenedAt,
        p.id AS productId,
        p.name AS product,
        g.id AS gestorId,
        g.name AS gestor,
        o.owner_code AS ownerCode,
        o.owner_name AS ownerName,
        COALESCE(SUM(CASE WHEN l.status='sold' THEN 1 ELSE 0 END), 0) AS soldCount,
        COALESCE(SUM(CASE WHEN l.status='sold' THEN l.gestor_share ELSE 0 END), 0) AS gestorEarned,
        COALESCE(SUM(CASE WHEN l.status='sold' THEN l.platform_share ELSE 0 END), 0) AS platformEarned
        FROM affiliate_trace_links t
        JOIN affiliate_products p ON p.id=t.product_id
        JOIN affiliate_gestores g ON g.id=t.gestor_id
        JOIN affiliate_owners o ON o.id=t.owner_id
        LEFT JOIN affiliate_leads l ON l.ref_token=t.ref_token
        GROUP BY t.id, t.masked_ref, t.ref_token, t.clicks, t.contact_opens, t.last_opened_at, p.id, p.name, g.id, g.name, o.owner_code, o.owner_name
        ORDER BY t.contact_opens DESC, t.clicks DESC, soldCount DESC, gestorEarned DESC
        LIMIT 25");
    $rows = [];
    foreach ($st->fetchAll() as $row) {
        $clicks = (int)$row['clicks'];
        $opens = (int)$row['contactOpens'];
        $sold = (int)$row['soldCount'];
        $rows[] = [
            'maskedRef' => (string)$row['maskedRef'],
            'link' => 'https://www.palweb.net/rac/refer/?product=' . rawurlencode((string)$row['productId']) . '&ref=' . rawurlencode((string)$row['refToken']),
            'product' => (string)$row['product'],
            'gestor' => (string)$row['gestor'],
            'gestorId' => (string)$row['gestorId'],
            'owner' => trim((string)$row['ownerCode'] . ' · ' . (string)$row['ownerName']),
            'clicks' => $clicks,
            'leads' => $opens,
            'sold' => $sold,
            'ctr' => $clicks > 0 ? round(($opens / $clicks) * 100, 1) : 0,
            'closeRate' => $opens > 0 ? round(($sold / $opens) * 100, 1) : 0,
            'gestorEarned' => round((float)$row['gestorEarned'], 2),
            'platformEarned' => round((float)$row['platformEarned'], 2),
            'lastOpenedAt' => (string)($row['lastOpenedAt'] ?? ''),
        ];
    }
    return $rows;
}

function aff_product_media(array $product): array {
    $webp = trim((string)($product['image_webp_url'] ?? ''));
    $img = trim((string)($product['image_url'] ?? ''));
    $thumb = trim((string)($product['image_thumb_url'] ?? ''));
    return [
        'imageUrl' => $img,
        'imageWebpUrl' => $webp,
        'imageThumbUrl' => $thumb,
        'hasImage' => $webp !== '' || $img !== '',
    ];
}

function aff_store_product_image(string $productId, string $imageData): array {
    $imageData = trim($imageData);
    if ($imageData === '') {
        return ['image_url' => '', 'image_webp_url' => ''];
    }
    if (!preg_match('#^data:(image/[a-zA-Z0-9.+-]+);base64,(.+)$#', $imageData, $m)) {
        throw new InvalidArgumentException('invalid_image_data');
    }
    $mime = strtolower($m[1]);
    $allowed = [
        'image/webp' => 'webp',
        'image/png' => 'png',
        'image/jpeg' => 'jpg',
    ];
    if (!isset($allowed[$mime])) {
        throw new InvalidArgumentException('unsupported_image_type');
    }
    $bin = base64_decode($m[2], true);
    if ($bin === false || strlen($bin) < 32) {
        throw new InvalidArgumentException('invalid_image_payload');
    }
    $dirFs = __DIR__ . '/../uploads/rac/products';
    if (!is_dir($dirFs) && !mkdir($dirFs, 0775, true) && !is_dir($dirFs)) {
        throw new RuntimeException('image_dir_create_failed');
    }
    $base = 'rac_' . preg_replace('/[^A-Za-z0-9_-]/', '', $productId);
    $webpFs = $dirFs . '/' . $base . '.webp';
    $thumbFs = $dirFs . '/' . $base . '_thumb.webp';
    if (file_put_contents($webpFs, $bin) === false) {
        throw new RuntimeException('image_write_failed');
    }
    $thumbOk = false;
    if (function_exists('imagecreatefromwebp') && function_exists('imagewebp')) {
        $imgRes = @imagecreatefromwebp($webpFs);
        if ($imgRes) {
            $srcW = imagesx($imgRes);
            $srcH = imagesy($imgRes);
            $maxW = 480;
            $maxH = 360;
            $ratio = min($maxW / max($srcW, 1), $maxH / max($srcH, 1), 1);
            $dstW = max(1, (int)round($srcW * $ratio));
            $dstH = max(1, (int)round($srcH * $ratio));
            $thumbRes = imagecreatetruecolor($dstW, $dstH);
            imagealphablending($thumbRes, true);
            imagesavealpha($thumbRes, true);
            imagecopyresampled($thumbRes, $imgRes, 0, 0, 0, 0, $dstW, $dstH, $srcW, $srcH);
            $thumbOk = @imagewebp($thumbRes, $thumbFs, 78);
            imagedestroy($thumbRes);
            imagedestroy($imgRes);
        }
    }
    if (!$thumbOk) {
        @copy($webpFs, $thumbFs);
    }
    return [
        'image_url' => '/uploads/rac/products/' . $base . '.webp',
        'image_webp_url' => '/uploads/rac/products/' . $base . '.webp',
        'image_thumb_url' => '/uploads/rac/products/' . $base . '_thumb.webp',
    ];
}

function aff_generate_lead_id(PDO $pdo): string {
    for ($i = 0; $i < 8; $i += 1) {
        $candidate = 'L' . date('YmdHis') . strtoupper(bin2hex(random_bytes(2)));
        $st = $pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE id=?");
        $st->execute([$candidate]);
        if ((int)$st->fetchColumn() === 0) {
            return $candidate;
        }
    }
    return 'L' . date('YmdHis') . strtoupper(bin2hex(random_bytes(4)));
}

function aff_delete_product_image(string $productId): array {
    $base = 'rac_' . preg_replace('/[^A-Za-z0-9_-]/', '', $productId);
    $dirFs = __DIR__ . '/../uploads/rac/products';
    foreach ([$dirFs . '/' . $base . '.webp', $dirFs . '/' . $base . '_thumb.webp'] as $file) {
        if (is_file($file)) {
            @unlink($file);
        }
    }
    return [
        'image_url' => '',
        'image_webp_url' => '',
        'image_thumb_url' => '',
    ];
}

function aff_wallet_reconciliation(PDO $pdo, int $ownerId): array {
    $st = $pdo->prepare("SELECT
        COALESCE(SUM(delta_available), 0) AS available_delta,
        COALESCE(SUM(delta_blocked), 0) AS blocked_delta
        FROM affiliate_wallet_movements
        WHERE owner_id=?");
    $st->execute([$ownerId]);
    $row = $st->fetch() ?: ['available_delta' => 0, 'blocked_delta' => 0];

    $owner = aff_owner_by_id($pdo, $ownerId);
    $calcAvailable = round((float)$row['available_delta'], 2);
    $calcBlocked = round((float)$row['blocked_delta'], 2);
    $realAvailable = round((float)$owner['available_balance'], 2);
    $realBlocked = round((float)$owner['blocked_balance'], 2);

    return [
        'calculatedAvailable' => $calcAvailable,
        'calculatedBlocked' => $calcBlocked,
        'actualAvailable' => $realAvailable,
        'actualBlocked' => $realBlocked,
        'availableMismatch' => round($realAvailable - $calcAvailable, 2),
        'blockedMismatch' => round($realBlocked - $calcBlocked, 2),
        'ok' => abs($realAvailable - $calcAvailable) < 0.01 && abs($realBlocked - $calcBlocked) < 0.01,
    ];
}

function aff_repair_owner_wallet_from_ledger(PDO $pdo, int $ownerId): array {
    $check = aff_wallet_reconciliation($pdo, $ownerId);
    if ($check['ok']) {
        return $check;
    }
    $available = round((float)$check['calculatedAvailable'], 2);
    $blocked = round((float)$check['calculatedBlocked'], 2);
    $total = round($available + $blocked, 2);
    $st = $pdo->prepare("UPDATE affiliate_owners SET available_balance=?, blocked_balance=?, total_balance=? WHERE id=?");
    $st->execute([$available, $blocked, $total, $ownerId]);
    aff_record_audit($pdo, [
        'owner_id' => $ownerId,
        'event_type' => 'wallet_reconciled',
        'severity' => 'warning',
        'message' => 'Se corrigio el saldo del wallet desde el ledger',
        'context' => $check,
    ]);
    return aff_wallet_reconciliation($pdo, $ownerId);
}

function aff_expire_stale_holds(PDO $pdo): int {
    $st = $pdo->prepare("SELECT l.*, o.available_balance, o.blocked_balance, o.total_balance
        FROM affiliate_leads l
        JOIN affiliate_owners o ON o.id=l.owner_id
        WHERE l.status IN ('new','contacted','negotiating')
        AND l.locked_commission > 0
        AND l.contact_opened_at IS NOT NULL
        AND l.contact_opened_at < DATE_SUB(NOW(), INTERVAL 72 HOUR)
        ORDER BY l.id ASC");
    $st->execute();
    $expired = 0;
    foreach ($st->fetchAll() as $lead) {
        $pdo->beginTransaction();
        try {
            $ownerLock = $pdo->prepare("SELECT * FROM affiliate_owners WHERE id=? FOR UPDATE");
            $ownerLock->execute([(int)$lead['owner_id']]);
            $owner = $ownerLock->fetch();
            if (!$owner) {
                throw new RuntimeException('owner_not_found');
            }
            $locked = round((float)$lead['locked_commission'], 2);
            if ($locked > 0) {
                aff_apply_wallet_move($pdo, $owner, 'release_hold_expired', $locked, $locked, -$locked, (string)$lead['id'], 'Liberacion automatica de garantia vencida', ['reason' => 'stale_hold']);
            }
            $pdo->prepare("UPDATE affiliate_leads SET status='no_sale', no_sale_at=?, sold_at=NULL WHERE id=?")->execute([aff_now(), $lead['id']]);
            aff_record_audit($pdo, [
                'owner_id' => (int)$lead['owner_id'],
                'gestor_id' => (string)$lead['gestor_id'],
                'product_id' => (string)$lead['product_id'],
                'lead_id' => (string)$lead['id'],
                'event_type' => 'lead_hold_expired',
                'severity' => 'warning',
                'message' => 'Lead vencido; garantia liberada automaticamente',
                'context' => ['hours' => 72],
            ]);
            aff_recompute_owner_health($pdo, (int)$lead['owner_id']);
            aff_recompute_gestor_health($pdo, (string)$lead['gestor_id']);
            $pdo->commit();
            $expired++;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }
    return $expired;
}

function aff_refresh_audit_alerts(PDO $pdo): void {
    $pdo->exec("UPDATE affiliate_audit_alerts SET active=0");

    $inactive = $pdo->query("SELECT id, owner_code, owner_name FROM affiliate_owners WHERE status='suspended' OR available_balance <= 0")->fetchAll();
    $stAlert = $pdo->prepare("INSERT INTO affiliate_audit_alerts (owner_id, owner_label, alert_type, metric, risk, color, active) VALUES (?,?,?,?,?,?,1)");
    foreach ($inactive as $row) {
        $stAlert->execute([
            (int)$row['id'],
            $row['owner_code'] . ' (' . $row['owner_name'] . ')',
            'inactive',
            'Saldo disponible agotado o tienda suspendida',
            'SALDO AGOTADO',
            '#8a8165',
        ]);
    }

    $fraud = $pdo->query("SELECT owner_id, owner_code, owner_name, total_leads, sold_leads,
        ROUND((sold_leads / NULLIF(total_leads, 0)) * 100, 2) AS conversion_rate
        FROM (
            SELECT o.id AS owner_id, o.owner_code, o.owner_name,
                COUNT(l.id) AS total_leads,
                SUM(CASE WHEN l.status='sold' THEN 1 ELSE 0 END) AS sold_leads
            FROM affiliate_owners o
            LEFT JOIN affiliate_leads l ON l.owner_id=o.id
            GROUP BY o.id, o.owner_code, o.owner_name
        ) x
        WHERE total_leads >= 6 AND sold_leads = 0")->fetchAll();
    foreach ($fraud as $row) {
        $stAlert->execute([
            (int)$row['owner_id'],
            $row['owner_code'] . ' (' . $row['owner_name'] . ')',
            'fraud',
            $row['sold_leads'] . ' ventas / ' . $row['total_leads'] . ' contactos',
            'ALTO',
            '#ef5350',
        ]);
    }

    $medium = $pdo->query("SELECT owner_id, owner_code, owner_name, total_leads, sold_leads,
        ROUND((sold_leads / NULLIF(total_leads, 0)) * 100, 2) AS conversion_rate
        FROM (
            SELECT o.id AS owner_id, o.owner_code, o.owner_name,
                COUNT(l.id) AS total_leads,
                SUM(CASE WHEN l.status='sold' THEN 1 ELSE 0 END) AS sold_leads
            FROM affiliate_owners o
            LEFT JOIN affiliate_leads l ON l.owner_id=o.id
            GROUP BY o.id, o.owner_code, o.owner_name
        ) x
        WHERE total_leads >= 6 AND sold_leads > 0 AND ROUND((sold_leads / NULLIF(total_leads, 0)) * 100, 2) < 12")->fetchAll();
    foreach ($medium as $row) {
        $stAlert->execute([
            (int)$row['owner_id'],
            $row['owner_code'] . ' (' . $row['owner_name'] . ')',
            'fraud',
            $row['sold_leads'] . ' ventas / ' . $row['total_leads'] . ' contactos',
            'MEDIO',
            '#ff8c00',
        ]);
    }
}

function aff_apply_wallet_move(PDO $pdo, array &$owner, string $movementType, float $amount, float $deltaAvailable, float $deltaBlocked, ?string $leadId, string $note, array $meta = []): void {
    $availableBefore = round((float)$owner['available_balance'], 2);
    $blockedBefore = round((float)$owner['blocked_balance'], 2);
    $availableAfter = round($availableBefore + $deltaAvailable, 2);
    $blockedAfter = round($blockedBefore + $deltaBlocked, 2);
    if ($availableAfter < 0 || $blockedAfter < 0) {
        throw new RuntimeException('wallet_underflow');
    }
    $totalAfter = round($availableAfter + $blockedAfter, 2);
    $owner['available_balance'] = $availableAfter;
    $owner['blocked_balance'] = $blockedAfter;
    $owner['total_balance'] = $totalAfter;

    $st = $pdo->prepare("UPDATE affiliate_owners SET available_balance=?, blocked_balance=?, total_balance=? WHERE id=?");
    $st->execute([$availableAfter, $blockedAfter, $totalAfter, (int)$owner['id']]);

    $ins = $pdo->prepare("INSERT INTO affiliate_wallet_movements (owner_id, lead_id, movement_type, amount, delta_available, delta_blocked, available_before, available_after, blocked_before, blocked_after, note, metadata_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $ins->execute([
        (int)$owner['id'],
        $leadId,
        $movementType,
        round($amount, 2),
        round($deltaAvailable, 2),
        round($deltaBlocked, 2),
        $availableBefore,
        $availableAfter,
        $blockedBefore,
        $blockedAfter,
        $note,
        $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        aff_now(),
    ]);
}

function aff_sign_ref(string $productId, string $gestorId): string {
    $payload = [
        'p' => $productId,
        'g' => $gestorId,
        'ts' => time(),
        'n' => bin2hex(random_bytes(5)),
    ];
    $encoded = aff_b64url_encode(json_encode($payload, JSON_UNESCAPED_UNICODE));
    $signature = aff_b64url_encode(hash_hmac('sha256', $encoded, aff_link_secret(), true));
    return $encoded . '.' . $signature;
}

function aff_verify_ref(string $token): ?array {
    if ($token === '' || strpos($token, '.') === false) {
        return null;
    }
    [$encoded, $signature] = explode('.', $token, 2);
    $expected = aff_b64url_encode(hash_hmac('sha256', $encoded, aff_link_secret(), true));
    if (!hash_equals($expected, $signature)) {
        return null;
    }
    $payload = json_decode(aff_b64url_decode($encoded), true);
    if (!is_array($payload) || empty($payload['p']) || empty($payload['g'])) {
        return null;
    }
    return $payload;
}

function aff_mask_ref(string $token): string {
    return substr(hash('sha256', $token), 0, 12);
}

function aff_create_trace_link(PDO $pdo, string $productId, string $gestorId): array {
    $product = aff_product($pdo, $productId);
    $gestor = aff_gestor($pdo, $gestorId);
    $existing = $pdo->prepare("SELECT ref_token, masked_ref FROM affiliate_trace_links WHERE product_id=? AND gestor_id=? ORDER BY id DESC LIMIT 1");
    $existing->execute([$productId, $gestorId]);
    $found = $existing->fetch();
    if ($found) {
        return [
            'link' => 'https://www.palweb.net/rac/refer/?product=' . rawurlencode($productId) . '&ref=' . rawurlencode((string)$found['ref_token']),
            'ref' => (string)$found['ref_token'],
            'masked_ref' => (string)$found['masked_ref'],
            'gestor' => ['id' => $gestor['id'], 'name' => $gestor['name']],
        ];
    }
    $token = aff_sign_ref($productId, $gestorId);
    $masked = aff_mask_ref($token);
    $st = $pdo->prepare("INSERT INTO affiliate_trace_links (product_id, gestor_id, owner_id, ref_token, masked_ref, created_at) VALUES (?,?,?,?,?,?)");
    $st->execute([$productId, $gestorId, (int)$product['owner_id'], $token, $masked, aff_now()]);
    $pdo->prepare("UPDATE affiliate_gestores SET links = links + 1 WHERE id=?")->execute([$gestorId]);
    aff_record_audit($pdo, [
        'owner_id' => (int)$product['owner_id'],
        'gestor_id' => $gestorId,
        'product_id' => $productId,
        'event_type' => 'trace_link_created',
        'severity' => 'info',
        'message' => 'Se generó un enlace de traza RAC',
        'context' => ['masked_ref' => $masked, 'gestor' => $gestor['name']],
    ]);
    return [
        'link' => 'https://www.palweb.net/rac/refer/?product=' . rawurlencode($productId) . '&ref=' . rawurlencode($token),
        'ref' => $token,
        'masked_ref' => $masked,
        'gestor' => ['id' => $gestor['id'], 'name' => $gestor['name']],
    ];
}

function aff_daily_trends(PDO $pdo, int $days = 14): array {
    $days = max(1, min($days, 31));
    $st = $pdo->prepare("SELECT DATE(lead_date) AS day,
                                COUNT(*) AS leads_count,
                                SUM(CASE WHEN status IN ('contacted','negotiating','sold','no_sale') THEN 1 ELSE 0 END) AS contact_count,
                                SUM(CASE WHEN status='negotiating' THEN 1 ELSE 0 END) AS negotiating_count,
                                SUM(CASE WHEN status='sold' THEN 1 ELSE 0 END) AS sold_count,
                                SUM(CASE WHEN status='sold' THEN platform_share ELSE 0 END) AS revenue_amount
                           FROM affiliate_leads
                          WHERE lead_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
                          GROUP BY DATE(lead_date)
                          ORDER BY DATE(lead_date) ASC");
    $st->execute([$days - 1]);
    return array_map(function ($row) {
        return [
            'day' => (string)$row['day'],
            'leads' => (int)$row['leads_count'],
            'contacts' => (int)$row['contact_count'],
            'negotiating' => (int)$row['negotiating_count'],
            'sold' => (int)$row['sold_count'],
            'revenue' => round((float)$row['revenue_amount'], 2),
        ];
    }, $st->fetchAll());
}

function aff_owner_cohorts(PDO $pdo): array {
    $rows = $pdo->query("SELECT o.owner_code AS ownerCode,
                                o.owner_name AS ownerName,
                                COUNT(l.id) AS leads_count,
                                SUM(CASE WHEN l.status IN ('contacted','negotiating','sold','no_sale') THEN 1 ELSE 0 END) AS contacts_count,
                                SUM(CASE WHEN l.status='sold' THEN 1 ELSE 0 END) AS sold_count,
                                SUM(CASE WHEN l.status='sold' THEN l.platform_share ELSE 0 END) AS revenue_amount
                           FROM affiliate_owners o
                      LEFT JOIN affiliate_leads l ON l.owner_id = o.id
                          GROUP BY o.id, o.owner_code, o.owner_name
                          ORDER BY sold_count DESC, leads_count DESC, o.owner_code ASC
                          LIMIT 10")->fetchAll();
    return array_map(function ($row) {
        $leads = (int)$row['leads_count'];
        $contacts = (int)$row['contacts_count'];
        $sold = (int)$row['sold_count'];
        return [
            'ownerCode' => (string)$row['ownerCode'],
            'ownerName' => (string)$row['ownerName'],
            'leads' => $leads,
            'contacts' => $contacts,
            'sold' => $sold,
            'contactRate' => $leads > 0 ? round(($contacts / $leads) * 100, 2) : 0.0,
            'conversionRate' => $leads > 0 ? round(($sold / $leads) * 100, 2) : 0.0,
            'revenue' => round((float)$row['revenue_amount'], 2),
        ];
    }, $rows);
}

function aff_gestor_cohorts(PDO $pdo): array {
    $rows = $pdo->query("SELECT g.id,
                                g.name,
                                COUNT(l.id) AS leads_count,
                                SUM(CASE WHEN l.status IN ('contacted','negotiating','sold','no_sale') THEN 1 ELSE 0 END) AS contacts_count,
                                SUM(CASE WHEN l.status='sold' THEN 1 ELSE 0 END) AS sold_count,
                                SUM(CASE WHEN l.status='sold' THEN l.gestor_share ELSE 0 END) AS earned_amount
                           FROM affiliate_gestores g
                      LEFT JOIN affiliate_leads l ON l.gestor_id = g.id
                          GROUP BY g.id, g.name
                          ORDER BY sold_count DESC, leads_count DESC, g.id ASC
                          LIMIT 10")->fetchAll();
    return array_map(function ($row) {
        $leads = (int)$row['leads_count'];
        $contacts = (int)$row['contacts_count'];
        $sold = (int)$row['sold_count'];
        return [
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'leads' => $leads,
            'contacts' => $contacts,
            'sold' => $sold,
            'contactRate' => $leads > 0 ? round(($contacts / $leads) * 100, 2) : 0.0,
            'conversionRate' => $leads > 0 ? round(($sold / $leads) * 100, 2) : 0.0,
            'earned' => round((float)$row['earned_amount'], 2),
        ];
    }, $rows);
}

function aff_sponsored_roi(PDO $pdo): array {
    $rows = $pdo->query("SELECT p.id,
                                p.name,
                                o.owner_code AS ownerCode,
                                o.owner_name AS ownerName,
                                p.sponsor_rank AS sponsorRank,
                                o.advertising_budget AS advertisingBudget,
                                p.clicks,
                                p.leads,
                                p.sales,
                                (SELECT COALESCE(SUM(l.platform_share),0) FROM affiliate_leads l WHERE l.product_id = p.id AND l.status='sold') AS platformRevenue
                           FROM affiliate_products p
                           JOIN affiliate_owners o ON o.id = p.owner_id
                          WHERE p.is_featured = 1
                          ORDER BY p.sponsor_rank DESC, p.clicks DESC, p.id ASC
                          LIMIT 12")->fetchAll();
    return array_map(function ($row) {
        $budget = round((float)$row['advertisingBudget'], 2);
        $revenue = round((float)$row['platformRevenue'], 2);
        return [
            'id' => (string)$row['id'],
            'name' => (string)$row['name'],
            'ownerCode' => (string)$row['ownerCode'],
            'ownerName' => (string)$row['ownerName'],
            'sponsorRank' => (int)$row['sponsorRank'],
            'advertisingBudget' => $budget,
            'clicks' => (int)$row['clicks'],
            'leads' => (int)$row['leads'],
            'sales' => (int)$row['sales'],
            'platformRevenue' => $revenue,
            'roiPct' => $budget > 0 ? round((($revenue - $budget) / $budget) * 100, 2) : 0.0,
        ];
    }, $rows);
}

function aff_funnel_metrics(PDO $pdo): array {
    $row = $pdo->query("SELECT COUNT(*) AS leads_count,
                               SUM(CASE WHEN status IN ('contacted','negotiating','sold','no_sale') THEN 1 ELSE 0 END) AS contacts_count,
                               SUM(CASE WHEN status='negotiating' THEN 1 ELSE 0 END) AS negotiating_count,
                               SUM(CASE WHEN status='sold' THEN 1 ELSE 0 END) AS sold_count,
                               SUM(CASE WHEN status='no_sale' THEN 1 ELSE 0 END) AS no_sale_count
                          FROM affiliate_leads")->fetch();
    $leads = (int)($row['leads_count'] ?? 0);
    $contacts = (int)($row['contacts_count'] ?? 0);
    $negotiating = (int)($row['negotiating_count'] ?? 0);
    $sold = (int)($row['sold_count'] ?? 0);
    return [
        'clicks' => (int)$pdo->query("SELECT COALESCE(SUM(clicks),0) FROM affiliate_trace_links")->fetchColumn(),
        'leads' => $leads,
        'contacts' => $contacts,
        'negotiating' => $negotiating,
        'sold' => $sold,
        'noSale' => (int)($row['no_sale_count'] ?? 0),
        'leadRate' => $leads > 0 ? 100.0 : 0.0,
        'contactRate' => $leads > 0 ? round(($contacts / $leads) * 100, 2) : 0.0,
        'negotiatingRate' => $leads > 0 ? round(($negotiating / $leads) * 100, 2) : 0.0,
        'soldRate' => $leads > 0 ? round(($sold / $leads) * 100, 2) : 0.0,
    ];
}

function aff_bi_analytics(PDO $pdo): array {
    return [
        'dailyTrend' => aff_daily_trends($pdo, 14),
        'ownerCohorts' => aff_owner_cohorts($pdo),
        'gestorCohorts' => aff_gestor_cohorts($pdo),
        'sponsoredRoi' => aff_sponsored_roi($pdo),
        'funnel' => aff_funnel_metrics($pdo),
    ];
}

function aff_bootstrap(PDO $pdo): array {
    $expiredHolds = aff_expire_stale_holds($pdo);
    aff_refresh_audit_alerts($pdo);
    $auth = aff_auth_context();
    $role = (string)$auth['role'];
    $owner = aff_owner($pdo);
    $walletCheck = aff_repair_owner_wallet_from_ledger($pdo, (int)$owner['id']);
    $owner = aff_owner($pdo);
    if ($role === 'gestor') {
        $products = $pdo->prepare("SELECT p.id, p.name, p.category, p.price, p.stock, p.commission, p.commission_pct AS commissionPct, p.icon AS image, p.image_url, p.image_webp_url, p.image_thumb_url, p.brand, p.description, p.clicks, p.leads, p.sales, p.trending, p.is_featured AS isFeatured, p.sponsor_rank AS sponsorRank, p.coupon_label AS couponLabel, p.active FROM affiliate_products p JOIN affiliate_owners o ON o.id=p.owner_id WHERE p.active=1 AND o.status='active' AND o.available_balance > 0 ORDER BY p.is_featured DESC, p.sponsor_rank DESC, p.trending DESC, p.clicks DESC, p.created_at ASC");
        $products->execute();
        $leads = $pdo->prepare("SELECT id, product_id AS productId, gestor_id AS gestorId, client, client_name AS clientName, client_phone AS clientPhone, DATE_FORMAT(lead_date, '%Y-%m-%d') AS date, status, commission, locked_commission AS lockedCommission, gestor_share AS gestorShare, platform_share AS platformShare, trace_code AS traceCode, (SELECT name FROM affiliate_products p WHERE p.id = affiliate_leads.product_id LIMIT 1) AS product FROM affiliate_leads WHERE gestor_id=? ORDER BY lead_date DESC, id DESC LIMIT 200");
        $leads->execute([aff_current_gestor_id($pdo)]);
    } else {
        $products = $pdo->prepare("SELECT id, name, category, price, stock, commission, commission_pct AS commissionPct, icon AS image, image_url, image_webp_url, image_thumb_url, brand, description, clicks, leads, sales, trending, is_featured AS isFeatured, sponsor_rank AS sponsorRank, coupon_label AS couponLabel, active FROM affiliate_products WHERE owner_id=? ORDER BY active DESC, is_featured DESC, sponsor_rank DESC, created_at ASC");
        $products->execute([(int)$owner['id']]);
        $leads = $pdo->prepare("SELECT id, product_id AS productId, gestor_id AS gestorId, client, client_name AS clientName, client_phone AS clientPhone, DATE_FORMAT(lead_date, '%Y-%m-%d') AS date, status, commission, locked_commission AS lockedCommission, gestor_share AS gestorShare, platform_share AS platformShare, trace_code AS traceCode, (SELECT name FROM affiliate_products p WHERE p.id = affiliate_leads.product_id LIMIT 1) AS product FROM affiliate_leads WHERE owner_id=? ORDER BY lead_date DESC, id DESC LIMIT 200");
        $leads->execute([(int)$owner['id']]);
    }
    $gestores = $pdo->query("SELECT id, name, earnings, links, conversions, rating, status, reputation_score AS reputationScore, masked_code AS maskedCode FROM affiliate_gestores ORDER BY earnings DESC, id ASC");
    $alerts = $pdo->query("SELECT owner_label AS dueno, alert_type AS type, metric, risk, color FROM affiliate_audit_alerts WHERE active=1 ORDER BY id ASC");
    $owners = $pdo->query("SELECT owner_code, owner_name, status, reputation_score AS reputationScore, fraud_risk AS fraudRisk, subscription_plan AS subscriptionPlan, managed_service AS managedService FROM affiliate_owners ORDER BY owner_code ASC");
    $movements = $pdo->prepare("SELECT id, movement_type AS movementType, amount, delta_available AS deltaAvailable, delta_blocked AS deltaBlocked, note, created_at AS createdAt FROM affiliate_wallet_movements WHERE owner_id=? ORDER BY id DESC LIMIT 30");
    $movements->execute([(int)$owner['id']]);
    $events = $pdo->prepare("SELECT event_type AS eventType, severity, message, created_at AS createdAt FROM affiliate_audit_events WHERE owner_id=? OR owner_id IS NULL ORDER BY id DESC LIMIT 30");
    $events->execute([(int)$owner['id']]);
    $traceLinks = aff_trace_links_for_gestor($pdo, aff_current_gestor_id($pdo));
    $ownerProductStats = aff_owner_product_stats($pdo, (int)$owner['id']);
    $linkRankings = aff_admin_link_rankings($pdo);
    $pricing = aff_price_suggestions($pdo, (int)$owner['id']);
    $insights = aff_market_insights($pdo);
    $analytics = aff_bi_analytics($pdo);

    $volume = (float)$pdo->query("SELECT COALESCE(SUM(price * GREATEST(sales,1)),0) FROM affiliate_products WHERE active=1")->fetchColumn();
    $revenue = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN status='sold' THEN platform_share ELSE 0 END),0) FROM affiliate_leads")->fetchColumn();
    $activeOwners = (int)$pdo->query("SELECT COUNT(*) FROM affiliate_owners WHERE status='active'")->fetchColumn();
    $activeGestores = (int)$pdo->query("SELECT COUNT(*) FROM affiliate_gestores WHERE status='active'")->fetchColumn();
    $leadsToday = (int)$pdo->query("SELECT COUNT(*) FROM affiliate_leads WHERE lead_date = CURDATE()")->fetchColumn();
    $salesToday = (int)$pdo->query("SELECT COUNT(*) FROM affiliate_leads WHERE lead_date = CURDATE() AND status='sold'")->fetchColumn();
    $soldCount = (int)$pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE owner_id=? AND status='sold'")->execute([(int)$owner['id']]);
    $totalLeadCount = (int)$pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE owner_id=?")->execute([(int)$owner['id']]);

    $st = $pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE owner_id=? AND status='sold'");
    $st->execute([(int)$owner['id']]);
    $sold = (int)$st->fetchColumn();
    $st = $pdo->prepare("SELECT COUNT(*) FROM affiliate_leads WHERE owner_id=?");
    $st->execute([(int)$owner['id']]);
    $leadCount = (int)$st->fetchColumn();
    $conversionRate = $leadCount > 0 ? round(($sold / $leadCount) * 100, 2) : 0.0;

    return [
        'owner' => [
            'id' => (int)$owner['id'],
            'code' => (string)$owner['owner_code'],
            'name' => (string)$owner['owner_name'],
            'phone' => (string)($owner['phone'] ?? ''),
            'whatsapp' => (string)($owner['whatsapp_number'] ?? ''),
            'wallet' => [
                'available' => (float)$owner['available_balance'],
                'blocked' => (float)$owner['blocked_balance'],
                'total' => (float)$owner['total_balance'],
            ],
            'reputationScore' => (float)$owner['reputation_score'],
            'fraudRisk' => (string)$owner['fraud_risk'],
            'conversionRate' => $conversionRate,
            'subscriptionPlan' => (string)$owner['subscription_plan'],
            'managedService' => (bool)$owner['managed_service'],
        ],
        'products' => array_map(function ($row) {
            return array_merge($row, aff_product_media($row));
        }, $products->fetchAll()),
        'leads' => $leads->fetchAll(),
        'gestores' => $gestores->fetchAll(),
        'alerts' => $role === 'admin' ? $alerts->fetchAll() : [],
        'owners' => $role === 'admin' ? $owners->fetchAll() : [],
        'walletMovements' => $movements->fetchAll(),
        'auditEvents' => $role === 'admin' ? aff_recent_audit_events($pdo) : $events->fetchAll(),
        'traceLinks' => $traceLinks,
        'ownerProductStats' => $ownerProductStats,
        'linkRankings' => $role === 'admin' ? $linkRankings : [],
        'pricingSuggestions' => $pricing,
        'marketInsights' => $insights,
        'analytics' => $role === 'admin' ? $analytics : ['dailyTrend' => [], 'ownerCohorts' => [], 'gestorCohorts' => [], 'sponsoredRoi' => [], 'funnel' => ['clicks' => 0, 'leads' => 0, 'contacts' => 0, 'negotiating' => 0, 'sold' => 0, 'noSale' => 0, 'leadRate' => 0, 'contactRate' => 0, 'negotiatingRate' => 0, 'soldRate' => 0]],
        'walletTopups' => $role === 'admin' ? aff_wallet_topups($pdo) : aff_owner_wallet_topups($pdo, (int)$owner['id']),
        'billingCharges' => $role === 'admin' ? aff_billing_charges($pdo) : aff_owner_billing_charges($pdo, (int)$owner['id']),
        'paymentReconciliations' => $role === 'admin' ? aff_payment_reconciliations($pdo) : [],
        'externalPayments' => $role === 'admin' ? aff_external_payments($pdo) : [],
        'ownerAdminList' => $role === 'admin' ? aff_owner_admin_list($pdo) : [],
        'gestorAdminList' => $role === 'admin' ? aff_gestor_admin_list($pdo) : [],
        'affiliateUsers' => $role === 'admin' ? aff_user_admin_list($pdo) : [],
        'userRoleSummary' => $role === 'admin' ? aff_user_role_summary($pdo) : ['admin' => ['active' => 0, 'suspended' => 0, 'total' => 0], 'owner' => ['active' => 0, 'suspended' => 0, 'total' => 0], 'gestor' => ['active' => 0, 'suspended' => 0, 'total' => 0]],
        'accessAudit' => $role === 'admin' ? aff_access_audit_events($pdo) : [],
        'activeSessions' => $role === 'admin' ? aff_active_sessions($pdo) : [],
        'recentLockouts' => $role === 'admin' ? aff_recent_lockouts($pdo) : [],
        'subscriptionMetrics' => $role === 'admin' ? aff_subscription_metrics($pdo) : ['expectedMrr' => 0, 'overdueOwners' => 0, 'managedOwners' => 0, 'adsActiveOwners' => 0, 'pendingTopups' => 0],
        'sponsoredProducts' => $role === 'admin' ? aff_sponsored_products($pdo) : [],
        'advancedAudit' => $role === 'admin' ? aff_advanced_audit_signals($pdo) : ['owners' => [], 'gestores' => []],
        'walletReconciliation' => $walletCheck,
        'summary' => [
            'volumeTotal' => $volume,
            'revenue' => $revenue,
            'ownersActive' => $activeOwners,
            'gestoresActive' => $activeGestores,
            'leadsToday' => $leadsToday,
            'salesToday' => $salesToday,
            'expiredHolds' => $expiredHolds,
        ],
        'integrations' => [
            'telegramConfigured' => $role === 'admin' ? aff_telegram_enabled($pdo) : false,
        ],
        'integrationSettings' => $role === 'admin' ? aff_integration_settings($pdo) : ['telegramBotToken' => '', 'telegramConfigured' => false, 'defaultGestorId' => aff_current_gestor_id($pdo), 'defaultGestorName' => '', 'defaultGestorChatId' => ''],
        'health' => $role === 'admin' ? aff_read_health_status() : ['ok' => null, 'timestamp' => '', 'mode' => '', 'exit_code' => null, 'output' => [], 'timer' => ['enabled' => false, 'active' => false, 'next' => '', 'last' => ''], 'service' => ['active' => false, 'journal' => []], 'summary' => ['checks' => 0, 'okChecks' => 0, 'failedChecks' => 0]],
        'auth' => [
            'userId' => $auth['user_id'],
            'role' => $role,
            'displayName' => (string)$auth['display_name'],
            'username' => (string)$auth['username'],
            'expiresAt' => (string)($auth['expires_at'] ?? ''),
            'allowedUiRoles' => aff_allowed_ui_roles(),
        ],
        'server_time' => date('c')
    ];
}

function aff_create_product(PDO $pdo, array $input): array {
    $owner = aff_owner($pdo);
    $name = substr(trim((string)($input['name'] ?? '')), 0, 190);
    $category = substr(trim((string)($input['category'] ?? 'Tecnología')), 0, 80);
    $price = round((float)($input['price'] ?? 0), 2);
    $stock = max(0, (int)($input['stock'] ?? 0));
    $commission = round((float)($input['commission'] ?? 0), 2);
    $description = trim((string)($input['description'] ?? ''));
    $imageData = (string)($input['image_data'] ?? '');
    if ($name === '' || $price <= 0 || $commission <= 0) {
        throw new InvalidArgumentException('Datos del producto incompletos');
    }
    $id = 'P' . date('His') . substr((string)time(), -4);
    $commissionPct = round(($commission / max($price, 1)) * 100, 1);
    $icon = (string)($input['image'] ?? '📦');
    $brand = substr(trim((string)($input['brand'] ?? 'Nuevo')), 0, 80) ?: 'Nuevo';
    $couponLabel = substr(trim((string)($input['coupon_label'] ?? '')), 0, 120);
    $isFeatured = !empty($input['is_featured']) ? 1 : 0;
    $sponsorRank = max(0, (int)($input['sponsor_rank'] ?? 0));
    $media = aff_store_product_image($id, $imageData);
    $st = $pdo->prepare("INSERT INTO affiliate_products (id, owner_id, name, category, price, stock, commission, commission_pct, icon, image_url, image_webp_url, image_thumb_url, brand, description, coupon_label, clicks, leads, sales, trending, is_featured, sponsor_rank, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)");
    $st->execute([$id, (int)$owner['id'], $name, $category, $price, $stock, $commission, $commissionPct, $icon, $media['image_url'], $media['image_webp_url'], $media['image_thumb_url'], $brand, $description, $couponLabel, 0, 0, 0, 0, $isFeatured, $sponsorRank, aff_now()]);
    aff_record_audit($pdo, [
        'owner_id' => (int)$owner['id'],
        'product_id' => $id,
        'event_type' => 'product_created',
        'severity' => 'info',
        'message' => 'Producto publicado en RAC',
        'context' => ['name' => $name, 'price' => $price, 'commission' => $commission],
    ]);
    return [
        'id' => $id,
        'name' => $name,
        'category' => $category,
        'price' => $price,
        'stock' => $stock,
        'commission' => $commission,
        'commissionPct' => $commissionPct,
        'image' => $icon,
        'imageUrl' => $media['image_url'],
        'imageWebpUrl' => $media['image_webp_url'],
        'imageThumbUrl' => $media['image_thumb_url'],
        'hasImage' => $media['image_url'] !== '',
        'brand' => $brand,
        'couponLabel' => $couponLabel,
        'isFeatured' => $isFeatured,
        'sponsorRank' => $sponsorRank,
        'description' => $description,
        'clicks' => 0,
        'leads' => 0,
        'sales' => 0,
        'trending' => 0,
        'active' => 1,
    ];
}

function aff_update_product(PDO $pdo, array $input): array {
    $owner = aff_owner($pdo);
    $id = trim((string)($input['id'] ?? ''));
    if ($id === '') {
        throw new InvalidArgumentException('product_id_required');
    }
    $st = $pdo->prepare("SELECT * FROM affiliate_products WHERE id=? AND owner_id=? AND active=1 LIMIT 1");
    $st->execute([$id, (int)$owner['id']]);
    $current = $st->fetch();
    if (!$current) {
        throw new RuntimeException('product_not_found');
    }

    $name = substr(trim((string)($input['name'] ?? $current['name'])), 0, 190);
    $category = substr(trim((string)($input['category'] ?? $current['category'])), 0, 80);
    $price = round((float)($input['price'] ?? $current['price']), 2);
    $stock = max(0, (int)($input['stock'] ?? $current['stock']));
    $commission = round((float)($input['commission'] ?? $current['commission']), 2);
    $description = trim((string)($input['description'] ?? $current['description']));
    $brand = substr(trim((string)($input['brand'] ?? $current['brand'])), 0, 80) ?: 'Nuevo';
    $couponLabel = substr(trim((string)($input['coupon_label'] ?? ($current['coupon_label'] ?? ''))), 0, 120);
    $isFeatured = !empty($input['is_featured']) ? 1 : 0;
    $sponsorRank = max(0, (int)($input['sponsor_rank'] ?? ($current['sponsor_rank'] ?? 0)));
    $imageData = (string)($input['image_data'] ?? '');
    $removeImage = !empty($input['remove_image']);
    if ($name === '' || $price <= 0 || $commission <= 0) {
        throw new InvalidArgumentException('Datos del producto incompletos');
    }

    $commissionPct = round(($commission / max($price, 1)) * 100, 1);
    $media = [
        'image_url' => (string)($current['image_url'] ?? ''),
        'image_webp_url' => (string)($current['image_webp_url'] ?? ''),
        'image_thumb_url' => (string)($current['image_thumb_url'] ?? ''),
    ];
    if ($removeImage) {
        $media = aff_delete_product_image($id);
    }
    if (trim($imageData) !== '') {
        $media = aff_store_product_image($id, $imageData);
    }

    $upd = $pdo->prepare("UPDATE affiliate_products
        SET name=?, category=?, price=?, stock=?, commission=?, commission_pct=?, image_url=?, image_webp_url=?, image_thumb_url=?, brand=?, description=?, coupon_label=?, is_featured=?, sponsor_rank=?
        WHERE id=? AND owner_id=?");
    $upd->execute([
        $name,
        $category,
        $price,
        $stock,
        $commission,
        $commissionPct,
        $media['image_url'],
        $media['image_webp_url'],
        $media['image_thumb_url'],
        $brand,
        $description,
        $couponLabel,
        $isFeatured,
        $sponsorRank,
        $id,
        (int)$owner['id']
    ]);

    aff_record_audit($pdo, [
        'owner_id' => (int)$owner['id'],
        'product_id' => $id,
        'event_type' => 'product_updated',
        'severity' => 'info',
        'message' => 'Producto RAC actualizado',
        'context' => ['name' => $name, 'price' => $price, 'commission' => $commission, 'remove_image' => $removeImage],
    ]);

    return [
        'id' => $id,
        'name' => $name,
        'category' => $category,
        'price' => $price,
        'stock' => $stock,
        'commission' => $commission,
        'commissionPct' => $commissionPct,
        'image' => (string)$current['icon'],
        'imageUrl' => $media['image_url'],
        'imageWebpUrl' => $media['image_webp_url'],
        'imageThumbUrl' => $media['image_thumb_url'],
        'hasImage' => $media['image_url'] !== '' || $media['image_webp_url'] !== '',
        'brand' => $brand,
        'couponLabel' => $couponLabel,
        'isFeatured' => $isFeatured,
        'sponsorRank' => $sponsorRank,
        'description' => $description,
        'clicks' => (int)$current['clicks'],
        'leads' => (int)$current['leads'],
        'sales' => (int)$current['sales'],
        'trending' => (int)$current['trending'],
        'active' => (int)$current['active'],
    ];
}

function aff_toggle_product_active(PDO $pdo, string $id, int $active): array {
    $owner = aff_owner($pdo);
    $active = $active ? 1 : 0;
    $st = $pdo->prepare("UPDATE affiliate_products SET active=? WHERE id=? AND owner_id=?");
    $st->execute([$active, $id, (int)$owner['id']]);
    if ($st->rowCount() < 1) {
        throw new RuntimeException('product_not_found');
    }
    aff_record_audit($pdo, [
        'owner_id' => (int)$owner['id'],
        'product_id' => $id,
        'event_type' => $active ? 'product_activated' : 'product_deactivated',
        'severity' => 'info',
        'message' => $active ? 'Producto RAC reactivado' : 'Producto RAC desactivado',
        'context' => ['active' => $active],
    ]);
    $q = $pdo->prepare("SELECT id, name, category, price, stock, commission, commission_pct AS commissionPct, icon AS image, image_url, image_webp_url, image_thumb_url, brand, description, clicks, leads, sales, trending, coupon_label AS couponLabel, is_featured AS isFeatured, sponsor_rank AS sponsorRank, active FROM affiliate_products WHERE id=? AND owner_id=? LIMIT 1");
    $q->execute([$id, (int)$owner['id']]);
    $row = $q->fetch();
    if (!$row) {
        throw new RuntimeException('product_not_found');
    }
    return array_merge($row, aff_product_media($row));
}

function aff_load_lead_for_owner(PDO $pdo, string $id, int $ownerId): array {
    $q = $pdo->prepare("SELECT id, product_id AS productId, gestor_id AS gestorId, client, client_name AS clientName, client_phone AS clientPhone, DATE_FORMAT(lead_date, '%Y-%m-%d') AS date, status, commission, locked_commission AS lockedCommission, gestor_share AS gestorShare, platform_share AS platformShare, trace_code AS traceCode, (SELECT name FROM affiliate_products p WHERE p.id = affiliate_leads.product_id LIMIT 1) AS product FROM affiliate_leads WHERE id=? AND owner_id=? LIMIT 1");
    $q->execute([$id, $ownerId]);
    $row = $q->fetch();
    if (!$row) {
        throw new RuntimeException('lead_not_found');
    }
    return $row;
}

function aff_update_lead_status(PDO $pdo, string $id, string $status): array {
    $allowed = ['new','contacted','negotiating','sold','no_sale','fraud_suspected'];
    if (!in_array($status, $allowed, true)) {
        throw new InvalidArgumentException('invalid_lead_status');
    }
    $owner = aff_owner($pdo);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM affiliate_leads WHERE id=? AND owner_id=? FOR UPDATE");
        $st->execute([$id, (int)$owner['id']]);
        $lead = $st->fetch();
        if (!$lead) {
            throw new RuntimeException('lead_not_found');
        }
        $ownerLock = $pdo->prepare("SELECT * FROM affiliate_owners WHERE id=? FOR UPDATE");
        $ownerLock->execute([(int)$owner['id']]);
        $ownerRow = $ownerLock->fetch();
        $product = aff_product($pdo, (string)$lead['product_id']);
        $prevStatus = (string)$lead['status'];
        $locked = round((float)$lead['locked_commission'], 2);
        $gestorShare = round((float)$lead['gestor_share'], 2);
        $platformShare = round((float)$lead['platform_share'], 2);

        if ($status === 'sold' && $prevStatus !== 'sold' && $locked > 0) {
            aff_apply_wallet_move($pdo, $ownerRow, 'payout_gestor', $gestorShare, 0, -$gestorShare, (string)$lead['id'], 'Pago de comisión al gestor', ['gestor_id' => $lead['gestor_id']]);
            aff_apply_wallet_move($pdo, $ownerRow, 'platform_revenue', $platformShare, 0, -$platformShare, (string)$lead['id'], 'Revenue plataforma RAC', ['lead_id' => $lead['id']]);
            $pdo->prepare("UPDATE affiliate_gestores SET earnings = earnings + ?, conversions = conversions + 1 WHERE id=?")->execute([$gestorShare, $lead['gestor_id']]);
            $pdo->prepare("UPDATE affiliate_products SET sales = sales + 1 WHERE id=?")->execute([$lead['product_id']]);
            $pdo->prepare("UPDATE affiliate_leads SET sold_at=?, no_sale_at=NULL WHERE id=?")->execute([aff_now(), $lead['id']]);
            aff_notify_gestor_commission($pdo, $lead, $product, $gestorShare);
        } elseif ($status === 'no_sale' && $prevStatus !== 'no_sale' && $locked > 0) {
            aff_apply_wallet_move($pdo, $ownerRow, 'release_hold', $locked, $locked, -$locked, (string)$lead['id'], 'Liberación de garantía por venta no concretada', []);
            $pdo->prepare("UPDATE affiliate_leads SET no_sale_at=?, sold_at=NULL WHERE id=?")->execute([aff_now(), $lead['id']]);
        } elseif ($status === 'fraud_suspected') {
            $pdo->prepare("UPDATE affiliate_leads SET fraud_flag=1 WHERE id=?")->execute([$lead['id']]);
        }

        $pdo->prepare("UPDATE affiliate_leads SET status=? WHERE id=?")->execute([$status, $lead['id']]);
        aff_recompute_owner_health($pdo, (int)$owner['id']);
        aff_recompute_gestor_health($pdo, (string)$lead['gestor_id']);
        aff_refresh_audit_alerts($pdo);
        aff_record_audit($pdo, [
            'owner_id' => (int)$owner['id'],
            'gestor_id' => (string)$lead['gestor_id'],
            'product_id' => (string)$lead['product_id'],
            'lead_id' => (string)$lead['id'],
            'event_type' => 'lead_status_changed',
            'severity' => $status === 'fraud_suspected' ? 'warning' : 'info',
            'message' => 'Lead actualizado a ' . $status,
            'context' => ['from' => $prevStatus, 'to' => $status, 'product' => $product['name']],
        ]);
        $pdo->commit();
        return aff_load_lead_for_owner($pdo, $id, (int)$owner['id']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function aff_resolve_ref(PDO $pdo, string $productId, string $ref): array {
    $payload = aff_verify_ref($ref);
    if (!$payload || $payload['p'] !== $productId) {
        throw new RuntimeException('invalid_ref');
    }
    $product = aff_product($pdo, $productId);
    $gestor = aff_gestor($pdo, (string)$payload['g']);
    $owner = aff_owner_by_id($pdo, (int)$product['owner_id']);
    return [$payload, $product, $gestor, $owner];
}

function aff_owner_by_id(PDO $pdo, int $ownerId): array {
    $st = $pdo->prepare("SELECT * FROM affiliate_owners WHERE id=? LIMIT 1");
    $st->execute([$ownerId]);
    $row = $st->fetch();
    if (!$row) {
        throw new RuntimeException('owner_not_found');
    }
    return $row;
}

function aff_refer_bootstrap(PDO $pdo, string $productId, string $ref): array {
    [$payload, $product, $gestor, $owner] = aff_resolve_ref($pdo, $productId, $ref);
    $pdo->prepare("UPDATE affiliate_products SET clicks = clicks + 1 WHERE id=?")->execute([$productId]);
    $st = $pdo->prepare("UPDATE affiliate_trace_links SET clicks = clicks + 1, last_opened_at=? WHERE ref_token=?");
    $st->execute([aff_now(), $ref]);
    aff_record_audit($pdo, [
        'owner_id' => (int)$owner['id'],
        'gestor_id' => $gestor['id'],
        'product_id' => $productId,
        'event_type' => 'trace_opened',
        'severity' => 'info',
        'message' => 'Se abrió una ficha pública de producto RAC',
        'context' => ['masked_ref' => aff_mask_ref($ref)],
    ]);
    return [
        'product' => array_merge([
            'id' => $product['id'],
            'name' => $product['name'],
            'category' => $product['category'],
            'price' => (float)$product['price'],
            'stock' => (int)$product['stock'],
            'image' => $product['icon'],
            'brand' => $product['brand'],
            'description' => $product['description'],
            'couponLabel' => $product['coupon_label'],
        ], aff_product_media($product)),
        'owner' => [
            'name' => $owner['owner_name'],
            'zone' => $owner['geo_zone'],
            'reputationScore' => (float)$owner['reputation_score'],
            'whatsapp' => (string)($owner['whatsapp_number'] ?? ''),
        ],
        'gestor' => [
            'id' => $gestor['id'],
            'name' => $gestor['name'],
            'maskedCode' => $gestor['masked_code'],
        ],
        'maskedRef' => aff_mask_ref($ref),
    ];
}

function aff_trigger_contact(PDO $pdo, string $productId, string $ref, array $input): array {
    [$payload, $product, $gestor, $owner] = aff_resolve_ref($pdo, $productId, $ref);
    $clientName = substr(trim((string)($input['client_name'] ?? 'Cliente RAC')), 0, 120) ?: 'Cliente RAC';
    $clientPhone = substr(trim((string)($input['client_phone'] ?? '')), 0, 40);
    $clientLabel = $clientPhone !== '' ? $clientPhone : $clientName;
    $commission = round((float)$product['commission'], 2);
    if ($commission <= 0) {
        throw new RuntimeException('invalid_commission');
    }

    $pdo->beginTransaction();
    try {
        $ownerLock = $pdo->prepare("SELECT * FROM affiliate_owners WHERE id=? FOR UPDATE");
        $ownerLock->execute([(int)$owner['id']]);
        $ownerRow = $ownerLock->fetch();
        if ((float)$ownerRow['available_balance'] < $commission) {
            throw new RuntimeException('owner_insufficient_balance');
        }
        $leadId = aff_generate_lead_id($pdo);
        $traceCode = '#RAC-' . strtoupper(substr(hash('sha1', $leadId . $ref), 0, 6));
        $gestorShare = round($commission * 0.8, 2);
        $platformShare = round($commission - $gestorShare, 2);
        $whatsapp = preg_replace('/\D+/', '', (string)($ownerRow['whatsapp_number'] ?: $ownerRow['phone']));
        $message = "Hola, vengo de RAC con el código {$traceCode}. Me interesa comprar {$product['name']}";
        $contactUrl = $whatsapp !== '' ? 'https://wa.me/' . $whatsapp . '?text=' . rawurlencode($message) : '';

        aff_apply_wallet_move($pdo, $ownerRow, 'lead_hold', $commission, -$commission, $commission, $leadId, 'Bloqueo de comisión por apertura de contacto', ['product_id' => $productId, 'gestor_id' => $gestor['id']]);
        $ins = $pdo->prepare("INSERT INTO affiliate_leads (id, owner_id, product_id, gestor_id, client, client_name, client_phone, lead_date, status, commission, locked_commission, gestor_share, platform_share, trace_code, ref_token, triggered_at, contact_opened_at, contact_url, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $now = aff_now();
        $ins->execute([$leadId, (int)$ownerRow['id'], $productId, $gestor['id'], $clientLabel, $clientName, $clientPhone, date('Y-m-d'), 'contacted', $commission, $commission, $gestorShare, $platformShare, $traceCode, $ref, $now, $now, $contactUrl, $now]);
        $pdo->prepare("UPDATE affiliate_products SET leads = leads + 1 WHERE id=?")->execute([$productId]);
        $pdo->prepare("UPDATE affiliate_trace_links SET contact_opens = contact_opens + 1, last_opened_at=? WHERE ref_token=?")->execute([$now, $ref]);
        aff_recompute_owner_health($pdo, (int)$ownerRow['id']);
        aff_recompute_gestor_health($pdo, (string)$gestor['id']);
        aff_refresh_audit_alerts($pdo);
        aff_record_audit($pdo, [
            'owner_id' => (int)$ownerRow['id'],
            'gestor_id' => $gestor['id'],
            'product_id' => $productId,
            'lead_id' => $leadId,
            'event_type' => 'lead_triggered',
            'severity' => 'info',
            'message' => 'Se disparó un contacto RAC con garantía bloqueada',
            'context' => ['trace_code' => $traceCode, 'client_name' => $clientName],
        ]);
        $pdo->commit();
        return [
            'lead_id' => $leadId,
            'trace_code' => $traceCode,
            'redirect_url' => $contactUrl,
            'gestor_share' => $gestorShare,
            'platform_share' => $platformShare,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
