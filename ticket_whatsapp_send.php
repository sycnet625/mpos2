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

// ===== ENVIAR FACTURAS DE SESIÓN EN UN ZIP =====
if ($action === 'send_session_zip') {
    $archivosTemporales = [];
    $rutaZip = '';
    try {
        if (!class_exists('ZipArchive')) {
            throw new Exception('La extensión ZIP no está disponible en el servidor');
        }

        $idsRaw = json_decode((string)($_POST['ticket_ids'] ?? '[]'), true);
        $ids = is_array($idsRaw)
            ? array_values(array_unique(array_filter(array_map('intval', $idsRaw), static fn($id) => $id > 0)))
            : [];
        $telefonoWhatsapp = trim((string)($_POST['whatsapp'] ?? ''));
        $mensaje = trim((string)($_POST['mensaje'] ?? ''));

        if (empty($ids) || $telefonoWhatsapp === '') {
            throw new Exception('Datos incompletos');
        }
        $bridge = bot_validate_bridge_for_campaign();
        if (empty($bridge['ok'])) {
            $state = trim((string)($bridge['state'] ?? 'desconocido'));
            $msg = trim((string)($bridge['msg'] ?? ''));
            throw new Exception('WhatsApp Web no está conectado. Estado: ' . $state . ($msg !== '' ? " ({$msg})" : ''));
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmtVentas = $pdo->prepare(
            "SELECT id, id_caja, id_sucursal
             FROM ventas_cabecera
             WHERE id IN ($placeholders)
             ORDER BY id ASC"
        );
        $stmtVentas->execute($ids);
        $ventas = $stmtVentas->fetchAll(PDO::FETCH_ASSOC);
        if (count($ventas) !== count($ids)) {
            throw new Exception('Uno o más tickets no existen');
        }

        $sesionId = intval($ventas[0]['id_caja'] ?? 0);
        $sucursalId = intval($ventas[0]['id_sucursal'] ?? 0);
        $sucursalConfig = intval($config['id_sucursal'] ?? 0);
        foreach ($ventas as $venta) {
            if (intval($venta['id_caja'] ?? 0) !== $sesionId || intval($venta['id_sucursal'] ?? 0) !== $sucursalId) {
                throw new Exception('Todos los tickets deben pertenecer a la misma sesión y sucursal');
            }
        }
        if ($sucursalConfig > 0 && $sucursalId !== $sucursalConfig) {
            throw new Exception('La sesión no pertenece a la sucursal activa');
        }
        if ($sesionId < 1) {
            throw new Exception('Los tickets no pertenecen a una sesión de caja válida');
        }

        // La selección identifica la sesión; el ZIP siempre incluye todos sus tickets.
        $stmtSesionVentas = $pdo->prepare(
            "SELECT id, id_caja, id_sucursal
             FROM ventas_cabecera
             WHERE id_caja = ? AND id_sucursal = ?
             ORDER BY id ASC"
        );
        $stmtSesionVentas->execute([$sesionId, $sucursalId]);
        $ventas = $stmtSesionVentas->fetchAll(PDO::FETCH_ASSOC);
        if (empty($ventas)) {
            throw new Exception('La sesión no contiene tickets');
        }
        if (count($ventas) > 250) {
            throw new Exception('La sesión contiene demasiados tickets para un solo ZIP');
        }

        $waId = preg_replace('/[^0-9+]/', '', $telefonoWhatsapp);
        if (str_starts_with($waId, '+')) {
            $waId = substr($waId, 1);
        }
        if (strlen($waId) < 8) {
            throw new Exception('Número de WhatsApp inválido');
        }

        $rutaZip = tempnam(sys_get_temp_dir(), 'facturas_sesion_');
        if ($rutaZip === false) {
            throw new Exception('No se pudo preparar el archivo ZIP');
        }
        $zip = new ZipArchive();
        if ($zip->open($rutaZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new Exception('No se pudo crear el archivo ZIP');
        }

        $generator = new ComprobanteGenerator($pdo, $config);
        foreach ($ventas as $venta) {
            $idVenta = intval($venta['id']);
            $rutaPdf = sys_get_temp_dir() . '/factura_sesion_' . $sesionId . '_' . $idVenta . '_' . bin2hex(random_bytes(3)) . '.pdf';
            $generator->generarPDF($idVenta, $rutaPdf, 'factura');
            if (!is_file($rutaPdf)) {
                throw new Exception("No se pudo generar la factura del ticket #{$idVenta}");
            }
            $archivosTemporales[] = $rutaPdf;
            if (!$zip->addFile($rutaPdf, "factura_ticket_{$idVenta}.pdf")) {
                throw new Exception("No se pudo agregar la factura #{$idVenta} al ZIP");
            }
        }
        $zip->close();
        $zip = null;

        if (!is_file($rutaZip) || filesize($rutaZip) < 100) {
            throw new Exception('No se pudo generar el ZIP de facturas');
        }
        if (filesize($rutaZip) > 90 * 1024 * 1024) {
            throw new Exception('El ZIP supera el límite de 90 MB');
        }

        $nombreZip = 'facturas_sesion_' . $sesionId . '.zip';
        $job = [
            'id' => 'session_zip_' . $sesionId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)),
            'target_id' => $waId,
            'type' => 'document',
            'file_base64' => base64_encode(file_get_contents($rutaZip)),
            'filename' => $nombreZip,
            'mimetype' => 'application/zip',
            'caption' => $mensaje ?: "Facturas de la sesión #{$sesionId}",
        ];
        if (!bot_enqueue_bridge_job($job)) {
            throw new Exception('No se pudo encolar el ZIP en WhatsApp');
        }

        echo json_encode([
            'status' => 'success',
            'msg' => 'ZIP con ' . count($ventas) . ' factura(s) en cola de WhatsApp.',
            'job_id' => $job['id'],
            'filename' => $nombreZip,
        ]);
        exit;
    } catch (Throwable $e) {
        echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
        exit;
    } finally {
        if (isset($zip) && $zip instanceof ZipArchive) {
            $zip->close();
        }
        foreach ($archivosTemporales as $archivo) {
            @unlink($archivo);
        }
        if ($rutaZip !== '') {
            @unlink($rutaZip);
        }
    }
}

// ===== ENVIAR POR WHATSAPP =====
if ($action === 'send') {
    try {
        $idVenta = isset($_POST['id_venta']) ? intval($_POST['id_venta']) : 0;
        $tipoDoc = $_POST['tipo_doc'] ?? 'comprobante'; // comprobante, ticket, factura
        $source = trim((string)($_POST['source'] ?? ''));
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
        if ($tipoDoc === 'factura_eco') {
            $tipoDoc = 'factura';
        }

        if (!$idVenta || !$telefonoWhatsapp) {
            throw new Exception('Datos incompletos');
        }
        if ($tipoDoc === 'factura' && $source !== 'single_ticket') {
            throw new Exception('El historial solo permite enviar un ZIP único con todas las facturas. Recarga el POS.');
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
