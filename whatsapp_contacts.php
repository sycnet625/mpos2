<?php
/**
 * ARCHIVO: whatsapp_contacts.php
 * PROPÓSITO: Serve WhatsApp contacts WITHOUT requiring session auth.
 *           Used by ticket_view.php modal to load recipient contacts.
 */
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

function whatsapp_contacts_normalize(?string $telefono): string {
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

try {
    $idVenta = isset($_GET['id_venta']) ? intval($_GET['id_venta']) : 0;
    $clientId = isset($_GET['client_id']) ? intval($_GET['client_id']) : 0;
    $preferred = [];

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
            $preferred[] = [
                'id' => $idVenta,
                'nombre' => 'Venta #' . $idVenta . ' - ' . (string)($ventaContacto['cliente_nombre'] ?? 'Cliente'),
                'telefono' => whatsapp_contacts_normalize((string)($ventaContacto['cliente_telefono'] ?? ''))
            ];
            if ($clientId <= 0 && !empty($ventaContacto['id_cliente'])) {
                $clientId = (int)$ventaContacto['id_cliente'];
            }
        }
    }

    if ($clientId > 0) {
        $stmtCliente = $pdo->prepare("
            SELECT c.id, c.nombre, c.telefono, ct.numero
            FROM clientes c
            LEFT JOIN clientes_telefonos ct ON c.id = ct.id_cliente AND ct.activo = 1
            WHERE c.id = ? AND c.activo = 1
            ORDER BY ct.id ASC
            LIMIT 20
        ");
        $stmtCliente->execute([$clientId]);
        foreach ($stmtCliente->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $preferred[] = [
                'id' => (int)($row['id'] ?? 0),
                'nombre' => (string)($row['nombre'] ?? 'Cliente'),
                'telefono' => whatsapp_contacts_normalize((string)($row['numero'] ?? ''))
            ];
            $preferred[] = [
                'id' => (int)($row['id'] ?? 0),
                'nombre' => (string)($row['nombre'] ?? 'Cliente'),
                'telefono' => whatsapp_contacts_normalize((string)($row['telefono'] ?? ''))
            ];
        }
    }

    $stmt = $pdo->query(
        "SELECT DISTINCT
             c.id,
             c.nombre,
             CONCAT(COALESCE(ct.numero, c.telefono), '') AS telefono
         FROM clientes c
         LEFT JOIN clientes_telefonos ct ON c.id = ct.id_cliente AND ct.activo = 1
         WHERE c.activo = 1
           AND (ct.numero IS NOT NULL OR c.telefono IS NOT NULL)
         GROUP BY c.id, c.nombre
         ORDER BY c.nombre ASC
         LIMIT 100"
    );
    $contactos = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $normalized = [];
    $seen = [];

    foreach ($contactos as &$c) {
        $phone = whatsapp_contacts_normalize($c['telefono'] ?? '');
        if ($phone === '') continue;
        $key = preg_replace('/\D+/', '', $phone);
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $c['whatsapp'] = $phone;
        $normalized[] = $c;
    }
    unset($c);

    foreach (array_reverse($preferred) as $pref) {
        $phone = (string)($pref['telefono'] ?? '');
        if ($phone === '') continue;
        $key = preg_replace('/\D+/', '', $phone);
        if ($key === '' || isset($seen[$key])) continue;
        $seen[$key] = true;
        array_unshift($normalized, [
            'id' => (int)($pref['id'] ?? 0),
            'nombre' => (string)($pref['nombre'] ?? 'Cliente'),
            'telefono' => $phone,
            'whatsapp' => $phone
        ]);
    }

    echo json_encode(['status' => 'success', 'contactos' => $normalized], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
