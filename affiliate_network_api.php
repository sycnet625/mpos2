<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/affiliate_network/domain.php';
header('Content-Type: application/json; charset=utf-8');

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);

$action = $_GET['action'] ?? 'bootstrap';
$publicActions = ['refer_bootstrap', 'trigger_contact'];

if (!in_array($action, $publicActions, true)) {
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        http_response_code(401);
        echo json_encode(['status' => 'error', 'msg' => 'unauthorized']);
        exit;
    }
}

$input = json_decode(file_get_contents('php://input'), true) ?: [];

function aff_require_csrf(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $sessionToken = $_SESSION['affiliate_csrf_token'] ?? '';
    if (!is_string($header) || $header === '' || !is_string($sessionToken) || $sessionToken === '' || !hash_equals($sessionToken, $header)) {
        http_response_code(419);
        echo json_encode(['status' => 'error', 'msg' => 'invalid_csrf']);
        exit;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'bootstrap') {
        echo json_encode(['status' => 'success', 'data' => aff_bootstrap($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_leads') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rac_leads.csv"');
        echo aff_export_leads_csv($pdo);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_wallet') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rac_wallet.csv"');
        echo aff_export_wallet_csv($pdo);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_rankings') {
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rac_rankings.csv"');
        echo aff_export_rankings_csv($pdo);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'lead_financial_flow') {
        echo json_encode(['status' => 'success', 'data' => aff_lead_financial_flow($pdo, (string)($_GET['id'] ?? ''))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'product_create') {
        aff_require_csrf();
        echo json_encode(['status' => 'success', 'row' => aff_create_product($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'product_update') {
        aff_require_csrf();
        echo json_encode(['status' => 'success', 'row' => aff_update_product($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'product_toggle_active') {
        aff_require_csrf();
        echo json_encode(['status' => 'success', 'row' => aff_toggle_product_active($pdo, (string)($input['id'] ?? ''), (int)($input['active'] ?? 0))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'lead_update_status') {
        aff_require_csrf();
        echo json_encode(['status' => 'success', 'row' => aff_update_lead_status($pdo, (string)($input['id'] ?? ''), (string)($input['status'] ?? ''))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'trace_link_create') {
        aff_require_csrf();
        $productId = (string)($input['product_id'] ?? '');
        $gestorId = (string)($input['gestor_id'] ?? AFF_DEFAULT_GESTOR);
        echo json_encode(['status' => 'success', 'row' => aff_create_trace_link($pdo, $productId, $gestorId)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'integration_settings_update') {
        aff_require_csrf();
        echo json_encode(['status' => 'success', 'row' => aff_save_integration_settings($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'refer_bootstrap') {
        $productId = (string)($_GET['product'] ?? '');
        $ref = (string)($_GET['ref'] ?? '');
        echo json_encode(['status' => 'success', 'data' => aff_refer_bootstrap($pdo, $productId, $ref)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'trigger_contact') {
        $productId = (string)($input['product'] ?? '');
        $ref = (string)($input['ref'] ?? '');
        echo json_encode(['status' => 'success', 'data' => aff_trigger_contact($pdo, $productId, $ref, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    http_response_code(404);
    echo json_encode(['status' => 'error', 'msg' => 'unknown_action']);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
