<?php
// onat_libro_helpers.php — Construcción del Libro de Ingresos y Gastos foliado.
// Reglas ONAT: cada asiento numerado, cronológico, encadenado con hash para auditoría.

if (!function_exists('onat_libro_construir')) {
    /**
     * Reconstruye los asientos del Libro para una empresa y rango de fechas.
     * Lee de ventas_cabecera (ingresos) y gastos_historial (gastos), encadenando hash.
     */
    function onat_libro_construir(PDO $pdo, int $idEmpresa, string $desde, string $hasta): array
    {
        require_once __DIR__ . '/accounting_helpers.php';

        $asientos = [];

        $sqlVentas = "SELECT v.id, DATE(v.fecha) AS f, v.uuid_venta, v.total
                      FROM ventas_cabecera v
                      LEFT JOIN caja_sesiones s ON v.id_caja = s.id
                      WHERE v.id_empresa = ?
                        AND IFNULL(s.fecha_contable, DATE(v.fecha)) BETWEEN ? AND ?
                        AND " . ventas_reales_where_clause('v') . "
                      ORDER BY f ASC, v.id ASC";
        $stmt = $pdo->prepare($sqlVentas);
        $stmt->execute([$idEmpresa, $desde, $hasta]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $asientos[] = [
                'fecha'        => $r['f'],
                'tipo'         => 'INGRESO',
                'concepto'     => 'Venta ' . ($r['uuid_venta'] ?? ('#' . $r['id'])),
                'origen_tabla' => 'ventas_cabecera',
                'origen_id'    => (int)$r['id'],
                'monto'        => floatval($r['total']),
                'comprobante'  => $r['uuid_venta'] ?? ('V-' . $r['id']),
            ];
        }

        try {
            $sqlGastos = "SELECT id, DATE(fecha) AS f, concepto, categoria, monto
                          FROM gastos_historial
                          WHERE DATE(fecha) BETWEEN ? AND ?
                          ORDER BY f ASC, id ASC";
            $stmt = $pdo->prepare($sqlGastos);
            $stmt->execute([$desde, $hasta]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $asientos[] = [
                    'fecha'        => $r['f'],
                    'tipo'         => 'GASTO',
                    'concepto'     => trim(($r['categoria'] ?? '') . ' — ' . ($r['concepto'] ?? '')),
                    'origen_tabla' => 'gastos_historial',
                    'origen_id'    => (int)$r['id'],
                    'monto'        => floatval($r['monto']),
                    'comprobante'  => 'G-' . $r['id'],
                ];
            }
        } catch (Throwable $e) {}

        try {
            $sqlCompras = "SELECT id, DATE(fecha) AS f, proveedor, total, numero_factura
                           FROM compras_cabecera
                           WHERE id_empresa = ? AND DATE(fecha) BETWEEN ? AND ? AND estado = 'APLICADA'
                           ORDER BY f ASC, id ASC";
            $stmt = $pdo->prepare($sqlCompras);
            $stmt->execute([$idEmpresa, $desde, $hasta]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $asientos[] = [
                    'fecha'        => $r['f'],
                    'tipo'         => 'GASTO',
                    'concepto'     => 'Compra a ' . ($r['proveedor'] ?? 'proveedor'),
                    'origen_tabla' => 'compras_cabecera',
                    'origen_id'    => (int)$r['id'],
                    'monto'        => floatval($r['total']),
                    'comprobante'  => $r['numero_factura'] ?: ('C-' . $r['id']),
                ];
            }
        } catch (Throwable $e) {}

        usort($asientos, fn($a, $b) => strcmp($a['fecha'], $b['fecha']));

        $hashAnterior = str_repeat('0', 64);
        $folio = 0;
        foreach ($asientos as &$a) {
            $folio++;
            $a['folio'] = $folio;
            $a['hash_anterior'] = $hashAnterior;
            $payload = $folio . '|' . $a['fecha'] . '|' . $a['tipo'] . '|' . $a['monto']
                     . '|' . ($a['origen_tabla'] ?? '') . '|' . ($a['origen_id'] ?? '')
                     . '|' . $hashAnterior;
            $a['hash_actual'] = hash('sha256', $payload);
            $hashAnterior = $a['hash_actual'];
        }
        unset($a);
        return $asientos;
    }
}

if (!function_exists('onat_libro_persistir')) {
    /** Inserta/reemplaza los asientos de un período en onat_libro_asientos. */
    function onat_libro_persistir(PDO $pdo, int $idEmpresa, string $desde, string $hasta, array $asientos): int
    {
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare("DELETE FROM onat_libro_asientos
                                  WHERE id_empresa = ? AND fecha BETWEEN ? AND ?");
            $del->execute([$idEmpresa, $desde, $hasta]);

            $ins = $pdo->prepare("INSERT INTO onat_libro_asientos
                (id_empresa, fecha, folio, tipo, concepto, origen_tabla, origen_id, monto, comprobante, hash_anterior, hash_actual)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            foreach ($asientos as $a) {
                $ins->execute([
                    $idEmpresa, $a['fecha'], $a['folio'], $a['tipo'],
                    $a['concepto'], $a['origen_tabla'], $a['origen_id'],
                    $a['monto'], $a['comprobante'],
                    $a['hash_anterior'], $a['hash_actual'],
                ]);
            }
            $pdo->commit();
            return count($asientos);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
