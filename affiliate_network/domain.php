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
}

function aff_seed_demo_data(PDO $pdo): void {
    $owners = [
        ['D-0042', 'ElectroHavana', '+53 5555-0042', '5355550042', 'La Habana', 'basic', 0, 91, 'BAJO', 47500, 12750, 60250, 'active'],
        ['D-0078', 'Electrónica Sur', '+53 5555-0078', '5355550078', 'Santiago', 'managed', 1, 62, 'ALTO', 25000, 18000, 43000, 'active'],
        ['D-0031', 'Tienda Miramar', '+53 5555-0031', '5355550031', 'La Habana', 'basic', 0, 40, 'ALTO', 0, 0, 0, 'suspended'],
        ['D-0055', 'La Habana Electronics', '+53 5555-0055', '5355550055', 'La Habana', 'pro', 0, 74, 'MEDIO', 31000, 9000, 40000, 'active'],
    ];
    $stOwner = $pdo->prepare("INSERT INTO affiliate_owners (owner_code, owner_name, phone, whatsapp_number, geo_zone, subscription_plan, managed_service, reputation_score, fraud_risk, available_balance, blocked_balance, total_balance, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE owner_name=VALUES(owner_name), phone=VALUES(phone), whatsapp_number=VALUES(whatsapp_number), geo_zone=VALUES(geo_zone), subscription_plan=VALUES(subscription_plan), managed_service=VALUES(managed_service), reputation_score=VALUES(reputation_score), fraud_risk=VALUES(fraud_risk), available_balance=VALUES(available_balance), blocked_balance=VALUES(blocked_balance), total_balance=VALUES(total_balance), status=VALUES(status)");
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
    $st = $pdo->prepare("SELECT t.product_id AS productId, p.name AS product, t.ref_token AS refToken, t.masked_ref AS maskedRef, t.clicks, t.contact_opens AS contactOpens, p.commission, COALESCE(l.earned, 0) AS earned
        FROM affiliate_trace_links t
        JOIN affiliate_products p ON p.id=t.product_id
        LEFT JOIN (
            SELECT ref_token, ROUND(SUM(CASE WHEN status='sold' THEN gestor_share ELSE 0 END), 2) AS earned
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
            'earned' => (float)$row['earned'],
            'commission' => (float)$row['commission'],
        ];
    }
    return $rows;
}

function aff_market_insights(PDO $pdo): array {
    return [
        'zones' => $pdo->query("SELECT geo_zone AS zone, COUNT(*) AS owners, ROUND(AVG(reputation_score),2) AS reputation FROM affiliate_owners WHERE geo_zone IS NOT NULL AND geo_zone<>'' GROUP BY geo_zone ORDER BY owners DESC, zone ASC LIMIT 10")->fetchAll(),
        'categories' => $pdo->query("SELECT category, SUM(clicks) AS clicks, SUM(leads) AS leads, SUM(sales) AS sales FROM affiliate_products WHERE active=1 GROUP BY category ORDER BY clicks DESC, category ASC LIMIT 8")->fetchAll(),
        'plans' => $pdo->query("SELECT subscription_plan AS plan, COUNT(*) AS total FROM affiliate_owners GROUP BY subscription_plan ORDER BY total DESC, plan ASC")->fetchAll(),
    ];
}

function aff_product_media(array $product): array {
    $webp = trim((string)($product['image_webp_url'] ?? ''));
    $img = trim((string)($product['image_url'] ?? ''));
    return [
        'imageUrl' => $img,
        'imageWebpUrl' => $webp,
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
    if (file_put_contents($webpFs, $bin) === false) {
        throw new RuntimeException('image_write_failed');
    }
    return [
        'image_url' => '/uploads/rac/products/' . $base . '.webp',
        'image_webp_url' => '/uploads/rac/products/' . $base . '.webp',
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
    $products = $pdo->prepare("SELECT id, name, category, price, stock, commission, commission_pct AS commissionPct, icon AS image, image_url, image_webp_url, brand, description, clicks, leads, sales, trending, is_featured AS isFeatured, sponsor_rank AS sponsorRank, coupon_label AS couponLabel FROM affiliate_products WHERE owner_id=? AND active=1 ORDER BY is_featured DESC, sponsor_rank DESC, created_at ASC");
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
        'pricingSuggestions' => $pricing,
        'marketInsights' => $insights,
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
    $media = aff_store_product_image($id, $imageData);
    $st = $pdo->prepare("INSERT INTO affiliate_products (id, owner_id, name, category, price, stock, commission, commission_pct, icon, image_url, image_webp_url, brand, description, clicks, leads, sales, trending, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?)");
    $st->execute([$id, (int)$owner['id'], $name, $category, $price, $stock, $commission, $commissionPct, $icon, $media['image_url'], $media['image_webp_url'], $brand, $description, 0, 0, 0, 0, aff_now()]);
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
        'hasImage' => $media['image_url'] !== '',
        'brand' => $brand,
        'description' => $description,
        'clicks' => 0,
        'leads' => 0,
        'sales' => 0,
        'trending' => 0,
    ];
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
        $leadId = 'L' . date('His') . substr((string)time(), -4);
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
