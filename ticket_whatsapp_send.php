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

session_start();
if (!isset($_SESSION['admin_logged_in']) && !isset($_SESSION['cashier'])) {
    die(json_encode(['status' => 'error', 'msg' => 'No autorizado']));
}

$action = $_GET['action'] ?? '';

// ===== OBTENER CONTACTOS WHATSAPP =====
if ($action === 'get_contacts') {
    try {
        $clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : null;

        $sql = "SELECT DISTINCT
                    c.id,
                    c.nombre,
                    CONCAT(COALESCE(ct.numero, c.telefono), '') as telefono
                FROM clientes c
                LEFT JOIN clientes_telefonos ct ON c.id = ct.id_cliente AND ct.activo = 1
                WHERE c.activo = 1 AND (ct.numero IS NOT NULL OR c.telefono IS NOT NULL)
                GROUP BY c.id, c.nombre
                ORDER BY c.nombre ASC
                LIMIT 100";

        $stmt = $pdo->query($sql);
        $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Limpiar números de teléfono para WhatsApp (solo dígitos)
        foreach ($contactos as &$c) {
            $phone = preg_replace('/[^0-9+]/', '', $c['telefono'] ?? '');
            if (!str_starts_with($phone, '+')) {
                // Si no tiene +, asumir que es Cuba (+53)
                if (str_starts_with($phone, '53')) {
                    $phone = '+' . $phone;
                } elseif (!str_starts_with($phone, '0')) {
                    $phone = '+53' . ltrim($phone, '0');
                } else {
                    $phone = '+53' . ltrim($phone, '0');
                }
            }
            $c['whatsapp'] = $phone;
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

        echo json_encode(['status' => 'success', 'msg' => 'Documento enviado a WhatsApp']);
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
