<?php
/**
 * ARCHIVO: whatsapp_contacts.php
 * PROPÓSITO: Serve WhatsApp contacts WITHOUT requiring session auth.
 *           Used by ticket_view.php modal to load recipient contacts.
 */
ini_set('display_errors', 0);
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/db.php';

try {
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

    foreach ($contactos as &$c) {
        $phone = preg_replace('/[^0-9+]/', '', $c['telefono'] ?? '');
        if (!str_starts_with($phone, '+')) {
            if (str_starts_with($phone, '53')) {
                $phone = '+' . $phone;
            } else {
                $phone = '+53' . ltrim($phone, '0');
            }
        }
        $c['whatsapp'] = $phone;
    }

    echo json_encode(['status' => 'success', 'contactos' => $contactos], JSON_UNESCAPED_UNICODE);
} catch (Exception $e) {
    echo json_encode(['status' => 'error', 'msg' => $e->getMessage()]);
}
