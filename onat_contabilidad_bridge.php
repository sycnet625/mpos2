<?php
// onat_contabilidad_bridge.php — Puente entre módulo ONAT y contabilidad_diario.
// Centraliza el mapeo modelo→cuenta y la inserción/reverso de asientos de pago.

if (!function_exists('onat_cuenta_pasivo_modelo')) {
    /**
     * Devuelve la(s) cuenta(s) de pasivo donde se asienta el impuesto del modelo.
     * Para Mensual-Ventas devuelve dos cuentas (ventas + territorial) prorrateadas.
     * Cada elemento: ['cuenta'=>'240.X - Nombre', 'pct'=>fracción del total].
     */
    function onat_cuenta_pasivo_modelo(string $modelo): array {
        switch ($modelo) {
            case 'DJ-08':
            case 'DJ-Utilidades':
            case 'DJ-09':
            case 'SC-Trim':
                return [['cuenta' => '240.4 - Imp. Utilidades', 'pct' => 1.0]];
            case 'Mensual-Ventas':
                // 10% ventas + 1% territorial → ratio 10:1 ≈ 0.909 / 0.091
                return [
                    ['cuenta' => '240.1 - Imp. Ventas',      'pct' => 10.0/11.0],
                    ['cuenta' => '240.2 - Contrib. Local',   'pct' =>  1.0/11.0],
                ];
            case 'IM-FuerzaTrabajo':
                return [['cuenta' => '240.3 - Fuerza Trabajo', 'pct' => 1.0]];
            case 'SS-Aporte':
                return [['cuenta' => '240.5 - Seg. Social', 'pct' => 1.0]];
            case 'TCP-CuotaFija':
                return [['cuenta' => '240.6 - Cuota Fija TCP', 'pct' => 1.0]];
            case 'Retenciones':
                return [['cuenta' => '240.7 - Retenciones ONAT', 'pct' => 1.0]];
            default:
                return [['cuenta' => '240.9 - Otros Tributos', 'pct' => 1.0]];
        }
    }
}

if (!function_exists('onat_cuenta_pago')) {
    function onat_cuenta_pago(string $metodo): string {
        $m = strtolower(trim($metodo));
        if ($m === 'efectivo') return '101 - Efectivo';
        // Transfermovil, EnZona, Banco → cuenta bancaria
        return '110 - Banco';
    }
}

if (!function_exists('onat_registrar_pago_contable')) {
    /**
     * Inserta el asiento de pago de la declaración en contabilidad_diario.
     * Idempotente: si ya hay un asiento PAGO_ONAT con esa referencia_id, no duplica.
     * Devuelve true si insertó, false si ya existía o si el monto era 0.
     */
    function onat_registrar_pago_contable(PDO $pdo, array $decl, string $metodo, string $fechaPago): bool {
        $idDecl = (int)$decl['id'];
        $monto = (float)$decl['monto_total'];
        if ($monto <= 0) return false;

        // Idempotencia: ¿ya existe asiento de pago para esta declaración?
        $chk = $pdo->prepare("SELECT COUNT(*) FROM contabilidad_diario
                              WHERE asiento_tipo = 'PAGO_ONAT' AND referencia_id = ?");
        $chk->execute([$idDecl]);
        if ((int)$chk->fetchColumn() > 0) return false;

        $cuentaPago = onat_cuenta_pago($metodo);
        $pasivos = onat_cuenta_pasivo_modelo((string)$decl['modelo']);

        $detalle = $decl['modelo'] . ' ' . $decl['periodo_inicio'] . '→' . $decl['periodo_fin'];
        $insert = $pdo->prepare("INSERT INTO contabilidad_diario
            (fecha, asiento_tipo, referencia_id, cuenta, debe, haber, detalle)
            VALUES (?, 'PAGO_ONAT', ?, ?, ?, ?, ?)");

        $pdo->beginTransaction();
        try {
            $acumDebe = 0.0;
            $n = count($pasivos);
            foreach ($pasivos as $i => $p) {
                // Última partida absorbe el redondeo
                $parcial = ($i === $n - 1)
                    ? round($monto - $acumDebe, 2)
                    : round($monto * $p['pct'], 2);
                $acumDebe += $parcial;
                if ($parcial > 0) {
                    $insert->execute([$fechaPago, $idDecl, $p['cuenta'], $parcial, 0, "Pago ONAT: $detalle"]);
                }
            }
            // Contrapartida: salida de caja/banco por el total
            $insert->execute([$fechaPago, $idDecl, $cuentaPago, 0, $monto, "Pago $metodo — $detalle"]);
            $pdo->commit();
            return true;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('onat_factura_en_declaracion')) {
    /**
     * Devuelve la declaración Mensual-Ventas presentada/pagada que cubre la fecha dada,
     * o null si la factura aún no está incluida en una declaración cerrada.
     */
    function onat_factura_en_declaracion(PDO $pdo, int $idEmpresa, string $fechaFactura): ?array {
        $stmt = $pdo->prepare("SELECT id, modelo, periodo_inicio, periodo_fin, estado
                               FROM onat_declaraciones
                               WHERE id_empresa = ?
                                 AND modelo = 'Mensual-Ventas'
                                 AND estado IN ('presentada','pagada')
                                 AND ? BETWEEN periodo_inicio AND periodo_fin
                               ORDER BY id DESC LIMIT 1");
        $stmt->execute([$idEmpresa, substr($fechaFactura, 0, 10)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('onat_categorias_deducibles')) {
    /**
     * Categorías de gastos_historial que son deducibles para DJ-Utilidades / DJ-08.
     * Coincide con lo que los generadores reconocen.
     */
    function onat_categorias_deducibles(): array {
        return ['NOMINA','SALARIO','PERSONAL','SERVICIOS','ALQUILER','INSUMOS',
                'MATERIA_PRIMA','LUZ','AGUA','TRANSPORTE','MANTENIMIENTO','PUBLICIDAD'];
    }
}
