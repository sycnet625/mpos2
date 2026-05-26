<?php
/**
 * ARCHIVO: ticket_whatsapp_send.php
 * DESCRIPCIÓN: Genera PDF y envía por WhatsApp a través del bot
 */

ini_set('display_errors', 0);
error_reporting(E_ALL);
set_time_limit(60);

require_once 'db.php';
require_once 'config_loader.php';
require_once 'helpers/comprobante_generator.php';

// Dependencias del Bot de WhatsApp
if (!defined('POSBOT_API_ROOT')) define('POSBOT_API_ROOT', __DIR__);
require_once POSBOT_API_ROOT . '/posbot_api/bootstrap.php';
require_once POSBOT_API_ROOT . '/posbot_api/repository.php';
require_once POSBOT_API_ROOT . '/posbot_api/helpers/runtime.php';

header('Content-Type: application/json; charset=utf-8');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['admin_logged_in']) && !isset($_SESSION['cashier'])) {
    die(json_encode(['status' => 'error', 'msg' => 'No autorizado']));
}

$action = $_GET['action'] ?? '';

function normalizarWhatsappTicket(?string $telefono): string {
    $phone = preg_replace('/[^0-9+]/', '', (string)$telefono);
    if ($phone === '') return '';
    if (!str_starts_with($phone, '+')) {
        if (str_starts_with($phone, '53')) {
            $phone = '+' . $phone;
        } else {
            $phone = '+53' . ltrim($phone, '0');
        }
    }
    $digits = preg_replace('/\D+/', '', $phone);
    if (strlen($digits) < 8) return '';
    return '+' . $digits;
}

function agregarContactoTicket(array &$contactos, array &$vistos, string $nombre, ?string $telefono): void {
    $wa = normalizarWhatsappTicket($telefono);
    if ($wa === '') return;
    $key = preg_replace('/\D+/', '', $wa);
    if ($key === '' || isset($vistos[$key])) return;
    $vistos[$key] = true;
    $contactos[] = [
        'nombre' => trim($nombre) !== '' ? trim($nombre) : 'Cliente',
        'whatsapp' => $wa,
    ];
}

// ===== OBTENER CONTACTOS WHATSAPP =====
if ($action === 'get_contacts') {
    try {
        $clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;
        $idVenta = isset($_GET['id_venta']) ? intval($_GET['id_venta']) : 0;
        $contactos = [];
        $vistos = [];

        if ($idVenta > 0) {
            $stmtVenta = $pdo->prepare("
                SELECT id_cliente, cliente_nombre, cliente_telefono
                FROM ventas_cabecera
                WHERE id = ?
                LIMIT 1
            ");
            $stmtVenta->execute([$idVenta]);
            $ventaContacto = $stmtVenta->fetch(PDO::FETCH_ASSOC) ?: [];
            if ($ventaContacto) {
                agregarContactoTicket(
                    $contactos,
                    $vistos,
                    'Venta #' . $idVenta . ' - ' . (string)($ventaContacto['cliente_nombre'] ?? 'Cliente'),
                    (string)($ventaContacto['cliente_telefono'] ?? '')
                );
                if ($clientId === null && !empty($ventaContacto['id_cliente'])) {
                    $clientId = (int)$ventaContacto['id_cliente'];
                }
            }
        }

        if ($clientId !== null && $clientId > 0) {
            $stmtCliente = $pdo->prepare("
                SELECT c.nombre, c.telefono, ct.numero
                FROM clientes c
                LEFT JOIN clientes_telefonos ct ON c.id = ct.id_cliente AND ct.activo = 1
                WHERE c.id = ? AND c.activo = 1
                LIMIT 20
            ");
            $stmtCliente->execute([$clientId]);
            foreach ($stmtCliente->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                agregarContactoTicket($contactos, $vistos, (string)($row['nombre'] ?? 'Cliente'), (string)($row['numero'] ?? ''));
                agregarContactoTicket($contactos, $vistos, (string)($row['nombre'] ?? 'Cliente'), (string)($row['telefono'] ?? ''));
            }
        }

        $sql = "
            SELECT c.id, c.nombre, c.telefono, ct.numero
            FROM clientes c
            LEFT JOIN clientes_telefonos ct ON c.id = ct.id_cliente AND ct.activo = 1
            WHERE c.activo = 1
              AND (ct.numero IS NOT NULL OR c.telefono IS NOT NULL)
            ORDER BY c.nombre ASC, ct.id ASC
            LIMIT 150
        ";
        $stmt = $pdo->query($sql);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            agregarContactoTicket($contactos, $vistos, (string)($row['nombre'] ?? 'Cliente'), (string)($row['numero'] ?? ''));
            agregarContactoTicket($contactos, $vistos, (string)($row['nombre'] ?? 'Cliente'), (string)($row['telefono'] ?? ''));
        }

        echo json_encode(['status' => 'success', 'contactos' => $contactos]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }
}

// ===== ENVIAR POR WHATSAPP =====
if ($action === 'send') {
    try {
        $idVenta = isset($_POST['id_venta']) ? intval($_POST['id_venta']) : 0;
        $tipoDoc = $_POST['tipo_doc'] ?? 'comprobante'; // comprobante, ticket, factura
        $telefonoWhatsapp = trim($_POST['whatsapp'] ?? '');
        $mensaje = trim($_POST['mensaje'] ?? '');
        $markupPct = isset($_POST['markup_pct']) ? round(floatval($_POST['markup_pct']), 2) : 0.0;
        if ($markupPct < -99.99) {
            $markupPct = -99.99;
        }
        $priceView = strtolower(trim((string)($_POST['price_view'] ?? 'venta')));
        if (!in_array($priceView, ['venta', 'mayorista'], true)) {
            $priceView = 'venta';
        }

        if (!$idVenta || !$telefonoWhatsapp) {
            throw new Exception('Datos incompletos');
        }

        $bridge = bot_validate_bridge_for_campaign();
        if (empty($bridge['ok'])) {
            $state = trim((string)($bridge['state'] ?? 'desconocido'));
            $msg = trim((string)($bridge['msg'] ?? ''));
            throw new Exception('WhatsApp Web no está conectado. Estado: ' . $state . ($msg !== '' ? " ({$msg})" : ''));
        }

        // Limpiar número de WhatsApp
        $waId = preg_replace('/[^0-9+]/', '', $telefonoWhatsapp);
        if (str_starts_with($waId, '+')) {
            $waId = substr($waId, 1);
        }

        // Generar PDF según tipo
        $generator = new ComprobanteGenerator($pdo, $config);

        if ($tipoDoc === 'comprobante') {
            $rutaPDF = $generator->generarPDF($idVenta, null, 'comprobante', $markupPct, $priceView);
            $nombreDoc = "comprobante_$idVenta.pdf";
        } elseif ($tipoDoc === 'factura') {
            $rutaPDF = $generator->generarPDF($idVenta, null, 'factura', $markupPct, $priceView);
            $nombreDoc = "factura_$idVenta.pdf";
        } else {
            // Formato ticket térmico
            $rutaPDF = $generator->generarPDF($idVenta, null, 'ticket', $markupPct, $priceView);
            $nombreDoc = "ticket_$idVenta.pdf";
        }

        if (!file_exists($rutaPDF)) {
            throw new Exception('No se pudo generar el PDF');
        }

        // Leer PDF y convertir a base64
        $pdfContent = file_get_contents($rutaPDF);
        $pdfBase64 = base64_encode($pdfContent);

        // Encolar trabajo en WhatsApp bridge
        $job = [
            'id' => 'ticket_' . $idVenta . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)),
            'target_id' => $waId,
            'type' => 'document',
            'file_base64' => $pdfBase64,
            'filename' => $nombreDoc,
            'mimetype' => 'application/pdf',
            'caption' => $mensaje ?: "Documento: $nombreDoc"
        ];

        if (!bot_enqueue_bridge_job($job)) {
            throw new Exception('No se pudo encolar el mensaje en WhatsApp');
        }

        // Limpiar archivo temporal
        @unlink($rutaPDF);

        // Log de envío
        logEnvioWhatsApp($pdo, $idVenta, $waId, $tipoDoc);

        echo json_encode([
            'status' => 'success',
            'msg' => 'Documento en cola de WhatsApp. Verifica el estado del bridge si no llega en unos segundos.',
            'job_id' => $job['id']
        ]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    }
}

function logEnvioWhatsApp($pdo, $idVenta, $telefonoWhatsApp, $tipoDoc) {
    try {
        $sql = "INSERT INTO pos_bot_messages
                (wa_user_id, direction, msg_type, message_text, created_at)
                VALUES (?, 'out', ?, ?, NOW())";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            $telefonoWhatsApp,
            'document_' . $tipoDoc,
            "Documento $tipoDoc de venta #$idVenta"
        ]);
    } catch (Exception $e) {
        // Silent fail - logging is optional
    }
}

// Si no hay action, retornar error
echo json_encode(['status' => 'error', 'msg' => 'Acción no especificada']);
?>
