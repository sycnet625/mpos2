<?php

if (!defined('AFF_OWNER_CODE')) {
    define('AFF_OWNER_CODE', 'D-0042');
}
if (!defined('AFF_DEFAULT_GESTOR')) {
    define('AFF_DEFAULT_GESTOR', 'G001');
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

    aff_refresh_audit_alerts($pdo);
}

function aff_owner(PDO $pdo): array {
    $st = $pdo->prepare("SELECT * FROM affiliate_owners WHERE owner_code=? LIMIT 1");
    $st->execute([AFF_OWNER_CODE]);
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

function aff_integration_settings(PDO $pdo): array {
    $defaultGestorId = AFF_DEFAULT_GESTOR;
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

function aff_bootstrap(PDO $pdo): array {
    $expiredHolds = aff_expire_stale_holds($pdo);
    aff_refresh_audit_alerts($pdo);
    $owner = aff_owner($pdo);
    $walletCheck = aff_repair_owner_wallet_from_ledger($pdo, (int)$owner['id']);
    $owner = aff_owner($pdo);
    $products = $pdo->prepare("SELECT id, name, category, price, stock, commission, commission_pct AS commissionPct, icon AS image, image_url, image_webp_url, image_thumb_url, brand, description, clicks, leads, sales, trending, is_featured AS isFeatured, sponsor_rank AS sponsorRank, coupon_label AS couponLabel, active FROM affiliate_products WHERE owner_id=? ORDER BY active DESC, is_featured DESC, sponsor_rank DESC, created_at ASC");
    $products->execute([(int)$owner['id']]);
    $leads = $pdo->prepare("SELECT id, product_id AS productId, gestor_id AS gestorId, client, client_name AS clientName, client_phone AS clientPhone, DATE_FORMAT(lead_date, '%Y-%m-%d') AS date, status, commission, locked_commission AS lockedCommission, gestor_share AS gestorShare, platform_share AS platformShare, trace_code AS traceCode, (SELECT name FROM affiliate_products p WHERE p.id = affiliate_leads.product_id LIMIT 1) AS product FROM affiliate_leads WHERE owner_id=? ORDER BY lead_date DESC, id DESC LIMIT 200");
    $leads->execute([(int)$owner['id']]);
    $gestores = $pdo->query("SELECT id, name, earnings, links, conversions, rating, status, reputation_score AS reputationScore, masked_code AS maskedCode FROM affiliate_gestores ORDER BY earnings DESC, id ASC");
    $alerts = $pdo->query("SELECT owner_label AS dueno, alert_type AS type, metric, risk, color FROM affiliate_audit_alerts WHERE active=1 ORDER BY id ASC");
    $owners = $pdo->query("SELECT owner_code, owner_name, status, reputation_score AS reputationScore, fraud_risk AS fraudRisk, subscription_plan AS subscriptionPlan, managed_service AS managedService FROM affiliate_owners ORDER BY owner_code ASC");
    $movements = $pdo->prepare("SELECT id, movement_type AS movementType, amount, delta_available AS deltaAvailable, delta_blocked AS deltaBlocked, note, created_at AS createdAt FROM affiliate_wallet_movements WHERE owner_id=? ORDER BY id DESC LIMIT 30");
    $movements->execute([(int)$owner['id']]);
    $events = $pdo->prepare("SELECT event_type AS eventType, severity, message, created_at AS createdAt FROM affiliate_audit_events WHERE owner_id=? OR owner_id IS NULL ORDER BY id DESC LIMIT 30");
    $events->execute([(int)$owner['id']]);
    $traceLinks = aff_trace_links_for_gestor($pdo, AFF_DEFAULT_GESTOR);
    $ownerProductStats = aff_owner_product_stats($pdo, (int)$owner['id']);
    $linkRankings = aff_admin_link_rankings($pdo);
    $pricing = aff_price_suggestions($pdo, (int)$owner['id']);
    $insights = aff_market_insights($pdo);

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
        'alerts' => $alerts->fetchAll(),
        'owners' => $owners->fetchAll(),
        'walletMovements' => $movements->fetchAll(),
        'auditEvents' => $events->fetchAll(),
        'traceLinks' => $traceLinks,
        'ownerProductStats' => $ownerProductStats,
        'linkRankings' => $linkRankings,
        'pricingSuggestions' => $pricing,
        'marketInsights' => $insights,
        'walletTopups' => aff_wallet_topups($pdo),
        'ownerAdminList' => aff_owner_admin_list($pdo),
        'gestorAdminList' => aff_gestor_admin_list($pdo),
        'subscriptionMetrics' => aff_subscription_metrics($pdo),
        'sponsoredProducts' => aff_sponsored_products($pdo),
        'advancedAudit' => aff_advanced_audit_signals($pdo),
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
            'telegramConfigured' => aff_telegram_enabled($pdo),
        ],
        'integrationSettings' => aff_integration_settings($pdo),
        'health' => aff_read_health_status(),
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
