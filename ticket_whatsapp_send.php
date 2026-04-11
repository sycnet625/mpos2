<?php
/**
 * ARCHIVO: ticket_whatsapp_send.php
 * DESCRIPCIÓN: Genera PDF y envía por WhatsApp a través del bot
 */

require_once 'db.php';
require_once 'config_loader.php';
require_once 'helpers/comprobante_generator.php';

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
            // Usar comprobante_ventas.php
            $rutaPDF = $generator->generarPDF($idVenta);
            $nombreDoc = "comprobante_$idVenta.pdf";
        } elseif ($tipoDoc === 'factura') {
            // Generar como factura (usando ticket_to_invoice)
            // Por ahora, usar el comprobante como alternativa
            $rutaPDF = $generator->generarPDF($idVenta);
            $nombreDoc = "factura_$idVenta.pdf";
        } else {
            // ticket_view.php - convertir a PDF
            $urlTicket = "ticket_view.php?id=$idVenta";
            $rutaPDF = convertirHTMLaPDF($urlTicket, "ticket_$idVenta.pdf");
            $nombreDoc = "ticket_$idVenta.pdf";
        }

        if (!file_exists($rutaPDF)) {
            throw new Exception('No se pudo generar el PDF');
        }

        // Leer PDF y convertir a base64
        $pdfContent = file_get_contents($rutaPDF);
        $pdfBase64 = base64_encode($pdfContent);

        // Encolar trabajo en WhatsApp bridge
        require_once 'posbot_api/helpers/runtime.php';

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

// ===== FUNCIONES AUXILIARES =====

function convertirHTMLaPDF($urlRelativa, $nombreSalida) {
    $rutaTmp = "/tmp/" . $nombreSalida;

    // Opción 1: Intentar acceder vía HTTP localhost
    $urlCompleta = "http://localhost/" . ltrim($urlRelativa, '/');
    $html = @file_get_contents($urlCompleta);

    // Opción 2: Si falla HTTP, generar desde ComprobanteGenerator para tickets
    if (!$html && preg_match('/id=(\d+)/', $urlRelativa, $matches)) {
        try {
            global $pdo, $config;
            require_once __DIR__ . '/helpers/comprobante_generator.php';
            $generator = new ComprobanteGenerator($pdo, $config);
            $idVenta = intval($matches[1]);
            return $generator->generarPDF($idVenta);
        } catch (Exception $e) {
            // Continúa intentando
        }
    }

    if (!$html) {
        throw new Exception('No se pudo obtener el HTML de ' . $urlRelativa);
    }

    // Guardar HTML temporal
    $tmpHtml = "/tmp/html_" . uniqid() . ".html";
    file_put_contents($tmpHtml, $html);

    // Usar wkhtmltopdf para convertir el HTML
    if (file_exists('/usr/bin/wkhtmltopdf')) {
        $cmd = "export HOME=/tmp && /usr/bin/wkhtmltopdf --enable-local-file-access --load-error-handling ignore --quiet '$tmpHtml' '$rutaTmp' 2>&1";
        exec($cmd, $output, $returnCode);

        @unlink($tmpHtml);

        // Aceptar el PDF si existe y tiene contenido, aunque haya errores de recursos externos (código 1)
        if (file_exists($rutaTmp) && filesize($rutaTmp) > 1000) {
            return $rutaTmp;
        }
    }

    @unlink($tmpHtml);
    throw new Exception('No se pudo convertir el HTML a PDF con wkhtmltopdf');
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
