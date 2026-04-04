<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/affiliate_network/domain.php';
header('Content-Type: application/json; charset=utf-8');

aff_ensure_tables($pdo);
aff_seed_demo_data($pdo);

$action = $_GET['action'] ?? 'bootstrap';
$publicActions = ['refer_bootstrap', 'trigger_contact'];

if (!in_array($action, $publicActions, true)) {
    aff_session_start_if_needed();
    if (!aff_is_authenticated()) {
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

function aff_require_roles(array $roles): void {
    if (!aff_role_allowed($roles)) {
        http_response_code(403);
        echo json_encode(['status' => 'error', 'msg' => 'forbidden']);
        exit;
    }
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'bootstrap') {
        echo json_encode(['status' => 'success', 'data' => aff_bootstrap($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_leads') {
        aff_require_roles(['admin']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rac_leads.csv"');
        echo aff_export_leads_csv($pdo);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_wallet') {
        aff_require_roles(['admin']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rac_wallet.csv"');
        echo aff_export_wallet_csv($pdo);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'export_rankings') {
        aff_require_roles(['admin']);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="rac_rankings.csv"');
        echo aff_export_rankings_csv($pdo);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'lead_financial_flow') {
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'data' => aff_lead_financial_flow($pdo, (string)($_GET['id'] ?? ''))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'product_create') {
        aff_require_csrf();
        aff_require_roles(['admin', 'owner']);
        echo json_encode(['status' => 'success', 'row' => aff_create_product($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'product_update') {
        aff_require_csrf();
        aff_require_roles(['admin', 'owner']);
        echo json_encode(['status' => 'success', 'row' => aff_update_product($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'product_toggle_active') {
        aff_require_csrf();
        aff_require_roles(['admin', 'owner']);
        echo json_encode(['status' => 'success', 'row' => aff_toggle_product_active($pdo, (string)($input['id'] ?? ''), (int)($input['active'] ?? 0))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'lead_update_status') {
        aff_require_csrf();
        aff_require_roles(['admin', 'owner']);
        echo json_encode(['status' => 'success', 'row' => aff_update_lead_status($pdo, (string)($input['id'] ?? ''), (string)($input['status'] ?? ''))], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'trace_link_create') {
        aff_require_csrf();
        aff_require_roles(['admin', 'gestor']);
        $productId = (string)($input['product_id'] ?? '');
        $gestorId = aff_auth_role() === 'gestor'
            ? aff_current_gestor_id($pdo)
            : (string)($input['gestor_id'] ?? AFF_DEFAULT_GESTOR);
        echo json_encode(['status' => 'success', 'row' => aff_create_trace_link($pdo, $productId, $gestorId)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'integration_settings_update') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_save_integration_settings($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'owner_upsert') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_upsert_owner($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'gestor_upsert') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_upsert_gestor($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'wallet_topup_request') {
        aff_require_csrf();
        aff_require_roles(['admin', 'owner']);
        echo json_encode(['status' => 'success', 'row' => aff_request_wallet_topup($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'wallet_topup_review') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_review_wallet_topup($pdo, (int)($input['id'] ?? 0), (string)($input['decision'] ?? ''), (string)($input['note'] ?? ''))], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'billing_charge_create') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_create_billing_charge($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'payment_reconcile') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_reconcile_payment_reference($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'billing_generate') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_generate_billing_charges($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'payment_extract_import') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_import_payment_extract($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'payment_auto_reconcile') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_auto_reconcile_pending_imports($pdo)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_upsert') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_upsert_user($pdo, $input)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_password_reset') {
        aff_require_csrf();
        aff_require_roles(['admin']);
        echo json_encode(['status' => 'success', 'row' => aff_admin_reset_user_password($pdo, (int)($input['id'] ?? 0), (string)($input['password'] ?? ''))], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'user_change_password') {
        aff_require_csrf();
        echo json_encode(['status' => 'success', 'row' => aff_change_password($pdo, (string)($input['current_password'] ?? ''), (string)($input['new_password'] ?? ''))], JSON_UNESCAPED_UNICODE);
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
