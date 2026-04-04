<?php
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    http_response_code(401);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['status' => 'error', 'msg' => 'unauthorized']);
    exit;
}
require_once __DIR__ . '/db.php';
header('Content-Type: application/json; charset=utf-8');

define('AFF_OWNER_CODE', 'D-0042');

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);
$action = $_GET['action'] ?? 'bootstrap';

if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'bootstrap') {
    echo json_encode(['status' => 'success', 'data' => aff_bootstrap($pdo)], JSON_UNESCAPED_UNICODE);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'product_create') {
    $created = aff_create_product($pdo, $input);
    echo json_encode(['status' => 'success', 'row' => $created], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'lead_update_status') {
    $updated = aff_update_lead_status($pdo, (string)($input['id'] ?? ''), (string)($input['status'] ?? ''));
    echo json_encode(['status' => 'success', 'row' => $updated], JSON_UNESCAPED_UNICODE);
    exit;
}

http_response_code(404);
echo json_encode(['status' => 'error', 'msg' => 'unknown_action']);
exit;

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS affiliate_leads (
        id VARCHAR(20) PRIMARY KEY,
        owner_id INT NOT NULL,
        product_id VARCHAR(20) NOT NULL,
        gestor_id VARCHAR(20) NOT NULL,
        client VARCHAR(80) NOT NULL,
        lead_date DATE NOT NULL,
        status ENUM('sold','pending','no_sale') NOT NULL DEFAULT 'pending',
        commission DECIMAL(12,2) NOT NULL DEFAULT 0,
        trace_code VARCHAR(40) NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        CONSTRAINT fk_aff_lead_owner FOREIGN KEY (owner_id) REFERENCES affiliate_owners(id) ON DELETE CASCADE,
        CONSTRAINT fk_aff_lead_product FOREIGN KEY (product_id) REFERENCES affiliate_products(id) ON DELETE CASCADE,
        CONSTRAINT fk_aff_lead_gestor FOREIGN KEY (gestor_id) REFERENCES affiliate_gestores(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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
}

function aff_seed_demo_data(PDO $pdo): void {
    $owners = [
        ['D-0042', 'ElectroHavana', 47500, 12750, 60250, 'active'],
        ['D-0078', 'Electrónica Sur', 25000, 18000, 43000, 'active'],
        ['D-0031', 'Tienda Miramar', 0, 0, 0, 'suspended'],
        ['D-0055', 'La Habana Electronics', 31000, 9000, 40000, 'active'],
    ];
    $stOwner = $pdo->prepare("INSERT INTO affiliate_owners (owner_code, owner_name, available_balance, blocked_balance, total_balance, status)
        VALUES (?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            owner_name=VALUES(owner_name),
            available_balance=VALUES(available_balance),
            blocked_balance=VALUES(blocked_balance),
            total_balance=VALUES(total_balance),
            status=VALUES(status)");
    foreach ($owners as $row) {
        $stOwner->execute($row);
    }

    $ownerMap = [];
    foreach ($pdo->query("SELECT id, owner_code FROM affiliate_owners") as $row) {
        $ownerMap[$row['owner_code']] = (int)$row['id'];
    }

    $gestores = [
        ['G001', 'Carlos Méndez', 48750, 23, 15, 4.9, 'active'],
        ['G002', 'Lisandra Pérez', 32100, 18, 9, 4.7, 'active'],
        ['G003', 'Yordanis Cruz', 19800, 11, 5, 4.3, 'active'],
    ];
    $stGestor = $pdo->prepare("INSERT INTO affiliate_gestores (id, name, earnings, links, conversions, rating, status)
        VALUES (?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            name=VALUES(name),
            earnings=VALUES(earnings),
            links=VALUES(links),
            conversions=VALUES(conversions),
            rating=VALUES(rating),
            status=VALUES(status)");
    foreach ($gestores as $row) {
        $stGestor->execute($row);
    }

    $products = [
      ['P001','D-0042','iPhone 13 Pro 256GB','Tecnología',85000,3,3000,3.5,'📱','Apple','Cámara triple 12MP, chip A15 Bionic, pantalla ProMotion 120Hz. Excelente estado.',142,28,9,1],
      ['P002','D-0042','Samsung Galaxy S22','Tecnología',62000,5,2200,3.5,'📲','Samsung','6.1 pulgadas AMOLED, 128GB, 8GB RAM. Color negro. Desbloqueado.',98,19,6,0],
      ['P003','D-0042','Nevera LG 12 pies','Electrodomésticos',120000,2,6000,5,'🧊','LG','No frost, dispensador de agua, eficiencia energética A++. Entrega incluida.',201,41,15,1],
      ['P004','D-0042','Aire Acondicionado 12000 BTU','Electrodomésticos',95000,4,4750,5,'❄️','Midea','Inverter, bajo consumo, control remoto. Incluye instalación.',315,67,22,1],
      ['P005','D-0042','Moto G82 5G','Tecnología',45000,8,1800,4,'📳','Motorola','Pantalla pOLED 6.6, 128GB, NFC, batería 5000mAh.',77,14,4,0],
      ['P006','D-0042','Sofá 3 Plazas','Muebles',38000,1,1900,5,'🛋️','Local','Tela microfibra gris, estructura de madera maciza. Excelente calidad.',55,9,2,0],
      ['P007','D-0042','Laptop ASUS VivoBook 15','Tecnología',73000,3,2920,4,'💻','ASUS','Intel Core i5, 8GB RAM, SSD 512GB, pantalla Full HD. Windows 11.',189,38,11,1],
      ['P008','D-0042','Bicicleta Eléctrica','Transporte',55000,2,2750,5,'🚲','Generic','Batería 48V, autonomía 60km, velocidad máx 35km/h. Con cargador.',134,26,8,0],
    ];
    $stProduct = $pdo->prepare("INSERT INTO affiliate_products (id, owner_id, name, category, price, stock, commission, commission_pct, icon, brand, description, clicks, leads, sales, trending)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            owner_id=VALUES(owner_id),
            name=VALUES(name),
            category=VALUES(category),
            price=VALUES(price),
            stock=VALUES(stock),
            commission=VALUES(commission),
            commission_pct=VALUES(commission_pct),
            icon=VALUES(icon),
            brand=VALUES(brand),
            description=VALUES(description),
            clicks=VALUES(clicks),
            leads=VALUES(leads),
            sales=VALUES(sales),
            trending=VALUES(trending),
            active=1");
    foreach ($products as $row) {
        $row[1] = $ownerMap[$row[1]] ?? $ownerMap[AFF_OWNER_CODE];
        $stProduct->execute($row);
    }

    $leads = [
      ['L001','D-0042','P003','G001','+53 5xxx-1234','2025-06-01','sold',6000,'#LG-8821'],
      ['L002','D-0042','P001','G002','+53 5xxx-5678','2025-06-02','pending',3000,'#LG-8835'],
      ['L003','D-0042','P004','G001','+53 5xxx-9012','2025-06-03','sold',4750,'#LG-8847'],
      ['L004','D-0042','P005','G003','+53 5xxx-3456','2025-06-04','no_sale',1800,'#LG-8861'],
      ['L005','D-0042','P002','G001','+53 5xxx-7890','2025-06-05','pending',2200,'#LG-8872'],
    ];
    $stLead = $pdo->prepare("INSERT INTO affiliate_leads (id, owner_id, product_id, gestor_id, client, lead_date, status, commission, trace_code)
        VALUES (?,?,?,?,?,?,?,?,?)
        ON DUPLICATE KEY UPDATE
            owner_id=VALUES(owner_id),
            product_id=VALUES(product_id),
            gestor_id=VALUES(gestor_id),
            client=VALUES(client),
            lead_date=VALUES(lead_date),
            status=VALUES(status),
            commission=VALUES(commission),
            trace_code=VALUES(trace_code)");
    foreach ($leads as $row) {
        $row[1] = $ownerMap[$row[1]] ?? $ownerMap[AFF_OWNER_CODE];
        $stLead->execute($row);
    }

    $alerts = [
        [$ownerMap['D-0078'] ?? null, 'D-0078 (Electrónica Sur)', 'fraud', '0 ventas / 47 contactos', 'ALTO', '#ef5350'],
        [$ownerMap['D-0055'] ?? null, 'D-0112 (TechStore Oriente)', 'fraud', '1 venta / 38 contactos', 'MEDIO', '#ff8c00'],
        [$ownerMap['D-0031'] ?? null, 'D-0031 (Tienda Miramar)', 'inactive', 'Saldo: 0 CUP', 'SALDO AGOTADO', '#8a8165'],
    ];
    $pdo->exec("DELETE FROM affiliate_audit_alerts WHERE owner_label IN ('D-0078 (Electrónica Sur)','D-0112 (TechStore Oriente)','D-0031 (Tienda Miramar)')");
    $stAlert = $pdo->prepare("INSERT INTO affiliate_audit_alerts (owner_id, owner_label, alert_type, metric, risk, color) VALUES (?,?,?,?,?,?)");
    foreach ($alerts as $row) {
        $stAlert->execute($row);
    }
}

function aff_bootstrap(PDO $pdo): array {
    $owner = aff_owner($pdo);
    $products = $pdo->prepare("SELECT id, name, category, price, stock, commission, commission_pct AS commissionPct, icon AS image, brand, description, clicks, leads, sales, trending FROM affiliate_products WHERE owner_id=? AND active=1 ORDER BY created_at ASC");
    $products->execute([(int)$owner['id']]);
    $leads = $pdo->prepare("SELECT id, product_id AS productId, gestor_id AS gestorId, client, DATE_FORMAT(lead_date, '%Y-%m-%d') AS date, status, commission, trace_code AS traceCode, (SELECT name FROM affiliate_products p WHERE p.id = affiliate_leads.product_id LIMIT 1) AS product FROM affiliate_leads WHERE owner_id=? ORDER BY lead_date DESC, id DESC");
    $leads->execute([(int)$owner['id']]);
    $gestores = $pdo->query("SELECT id, name, earnings, links, conversions, rating, status FROM affiliate_gestores ORDER BY earnings DESC, id ASC");
    $alerts = $pdo->query("SELECT owner_label AS dueno, alert_type AS type, metric, risk, color FROM affiliate_audit_alerts WHERE active=1 ORDER BY id ASC");
    $owners = $pdo->query("SELECT owner_code, owner_name, status FROM affiliate_owners ORDER BY owner_code ASC");

    $volume = (float)$pdo->query("SELECT COALESCE(SUM(price * GREATEST(sales,1)),0) FROM affiliate_products WHERE active=1")->fetchColumn();
    $revenue = (float)$pdo->query("SELECT COALESCE(SUM(CASE WHEN status='sold' THEN commission * 0.2 ELSE 0 END),0) FROM affiliate_leads")->fetchColumn();
    $activeOwners = (int)$pdo->query("SELECT COUNT(*) FROM affiliate_owners WHERE status='active'")->fetchColumn();
    $activeGestores = (int)$pdo->query("SELECT COUNT(*) FROM affiliate_gestores WHERE status='active'")->fetchColumn();
    $leadsToday = (int)$pdo->query("SELECT COUNT(*) FROM affiliate_leads WHERE lead_date = CURDATE()")->fetchColumn();
    $salesToday = (int)$pdo->query("SELECT COUNT(*) FROM affiliate_leads WHERE lead_date = CURDATE() AND status='sold'")->fetchColumn();

    return [
        'owner' => [
            'id' => (int)$owner['id'],
            'code' => (string)$owner['owner_code'],
            'name' => (string)$owner['owner_name'],
            'wallet' => [
                'available' => (float)$owner['available_balance'],
                'blocked' => (float)$owner['blocked_balance'],
                'total' => (float)$owner['total_balance'],
            ]
        ],
        'products' => $products->fetchAll(),
        'leads' => $leads->fetchAll(),
        'gestores' => $gestores->fetchAll(),
        'alerts' => $alerts->fetchAll(),
        'owners' => $owners->fetchAll(),
        'summary' => [
            'volumeTotal' => $volume,
            'revenue' => $revenue,
            'ownersActive' => $activeOwners,
            'gestoresActive' => $activeGestores,
            'leadsToday' => $leadsToday,
            'salesToday' => $salesToday,
        ],
        'server_time' => date('c')
    ];
}

function aff_owner(PDO $pdo): array {
    $st = $pdo->prepare("SELECT * FROM affiliate_owners WHERE owner_code=? LIMIT 1");
    $st->execute([AFF_OWNER_CODE]);
    $row = $st->fetch();
    if (!$row) throw new RuntimeException('owner_not_found');
    return $row;
}

function aff_create_product(PDO $pdo, array $input): array {
    $owner = aff_owner($pdo);
    $name = substr(trim((string)($input['name'] ?? '')), 0, 190);
    $category = substr(trim((string)($input['category'] ?? 'Tecnología')), 0, 80);
    $price = round((float)($input['price'] ?? 0), 2);
    $stock = max(0, (int)($input['stock'] ?? 0));
    $commission = round((float)($input['commission'] ?? 0), 2);
    $description = trim((string)($input['description'] ?? ''));
    if ($name === '' || $price <= 0 || $stock < 0 || $commission <= 0) {
        throw new InvalidArgumentException('Datos del producto incompletos');
    }
    $id = 'P' . date('His') . substr((string)time(), -4);
    $commissionPct = round(($commission / max($price, 1)) * 100, 1);
    $icon = (string)($input['image'] ?? '📦');
    $brand = substr(trim((string)($input['brand'] ?? 'Nuevo')), 0, 80) ?: 'Nuevo';
    $st = $pdo->prepare("INSERT INTO affiliate_products (id, owner_id, name, category, price, stock, commission, commission_pct, icon, brand, description, clicks, leads, sales, trending, active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,1)");
    $st->execute([$id, (int)$owner['id'], $name, $category, $price, $stock, $commission, $commissionPct, $icon, $brand, $description, 0, 0, 0, 0]);
    return [
        'id' => $id,
        'name' => $name,
        'category' => $category,
        'price' => $price,
        'stock' => $stock,
        'commission' => $commission,
        'commissionPct' => $commissionPct,
        'image' => $icon,
        'brand' => $brand,
        'description' => $description,
        'clicks' => 0,
        'leads' => 0,
        'sales' => 0,
        'trending' => 0,
    ];
}

function aff_update_lead_status(PDO $pdo, string $id, string $status): array {
    $status = in_array($status, ['sold','pending','no_sale'], true) ? $status : 'pending';
    $owner = aff_owner($pdo);
    $st = $pdo->prepare("UPDATE affiliate_leads SET status=? WHERE id=? AND owner_id=?");
    $st->execute([$status, $id, (int)$owner['id']]);
    $q = $pdo->prepare("SELECT id, product_id AS productId, gestor_id AS gestorId, client, DATE_FORMAT(lead_date, '%Y-%m-%d') AS date, status, commission, trace_code AS traceCode, (SELECT name FROM affiliate_products p WHERE p.id = affiliate_leads.product_id LIMIT 1) AS product FROM affiliate_leads WHERE id=? AND owner_id=? LIMIT 1");
    $q->execute([$id, (int)$owner['id']]);
    $row = $q->fetch();
    if (!$row) throw new RuntimeException('lead_not_found');
    return $row;
}
