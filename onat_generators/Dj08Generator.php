<?php
// onat_generators/Dj08Generator.php — DJ-08 Impuesto sobre Ingresos Personales (anual).

require_once __DIR__ . '/BaseGenerator.php';

class Dj08Generator extends BaseGenerator
{
    public const MODELO = 'DJ-08';
    public const PERIODO_TIPO = 'anual';

    /** Calibrar contra la plantilla oficial cuando esté en disco. */
    protected const CELL_MAP = [
        'contribuyente_nombre'  => 'C5',
        'contribuyente_ci'      => 'C6',
        'periodo_anio'          => 'C7',
        'municipio'             => 'C8',
        'provincia'             => 'C9',
        'ingresos_brutos'       => 'D14',
        'gastos_reales'         => 'D15',
        'tope_gastos_pct'       => 'D16',
        'gastos_deducibles'     => 'D17',
        'base_imponible'        => 'D18',
        'minimo_exento'         => 'D19',
        'impuesto_calculado'    => 'D20',
        'pagos_a_cuenta'        => 'D21',
        'descuento_anticipado'  => 'D22',
        'descuento_digital'     => 'D23',
        'impuesto_a_pagar'      => 'D24',
    ];

    /**
     * Escala progresiva oficial 2025 (Cuba) para DJ-08.
     * Tramos: hasta X CUP → tasa.
     * Mantener actualizada cada año conforme al instructivo ONAT.
     */
    protected const ESCALA = [
        [10500,    0.00],
        [30000,    0.15],
        [60000,    0.20],
        [120000,   0.30],
        [180000,   0.40],
        [PHP_INT_MAX, 0.50],
    ];

    protected const MINIMO_EXENTO = 10500.0;

    public function calcular(int $anio, int $mes): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        $ingresos = $this->ventasBrutasRango($desde, $hasta);
        $costo    = $this->costoVentasRango($desde, $hasta);
        $gastos   = $this->gastosOperativosRango($desde, $hasta);
        $gastosReales = $costo + $gastos;

        // Tope de gastos deducibles configurado por actividad (TCP típico 80%).
        $topePct = floatval($this->fiscalRegime['tope_gastos_deducibles_pct'] ?? 80) / 100.0;
        $topeMonto = $ingresos * $topePct;
        $deducibles = min($gastosReales, $topeMonto);

        $base = max(0.0, $ingresos - $deducibles);
        $impuestoCalculado = $this->aplicarEscala($base);

        // Pagos a cuenta del año (anticipos mensuales ya declarados).
        $pagosACuenta = $this->pagosACuentaAnio($anio);

        $impuestoNeto = max(0.0, $impuestoCalculado - $pagosACuenta);

        // Bonificación 5% si paga antes del 28 de febrero del año siguiente.
        $hoy = new DateTimeImmutable('today');
        $fechaCorte = new DateTimeImmutable(sprintf('%04d-02-28', $anio + 1));
        $descAnticipado = ($hoy <= $fechaCorte) ? round($impuestoNeto * 0.05, 2) : 0.0;
        // Bonificación 3% por canal digital.
        $descDigital = !empty($this->fiscalRegime['paga_digital']) ? round($impuestoNeto * 0.03, 2) : 0.0;

        $impuestoFinal = max(0.0, round($impuestoNeto - $descAnticipado - $descDigital, 2));

        return [
            'periodo_inicio' => $desde,
            'periodo_fin'    => $hasta,
            'monto_total'    => $impuestoFinal,
            'datos' => [
                'contribuyente_nombre'  => $this->fiscalRegime['representante_nombre'] ?: ($this->config['marca_empresa_nombre'] ?? ''),
                'contribuyente_ci'      => $this->fiscalRegime['representante_ci'] ?? '',
                'periodo_anio'          => $anio,
                'municipio'             => $this->fiscalRegime['municipio_onat'] ?? '',
                'provincia'             => $this->fiscalRegime['provincia_onat'] ?? '',
                'ingresos_brutos'       => round($ingresos, 2),
                'gastos_reales'         => round($gastosReales, 2),
                'tope_gastos_pct'       => $topePct,
                'gastos_deducibles'     => round($deducibles, 2),
                'base_imponible'        => round($base, 2),
                'minimo_exento'         => self::MINIMO_EXENTO,
                'impuesto_calculado'    => round($impuestoCalculado, 2),
                'pagos_a_cuenta'        => round($pagosACuenta, 2),
                'descuento_anticipado'  => $descAnticipado,
                'descuento_digital'     => $descDigital,
                'impuesto_a_pagar'      => $impuestoFinal,
            ],
        ];
    }

    /** Suma los anticipos mensuales (Mensual-Ventas) ya declarados como pagados/presentados. */
    protected function pagosACuentaAnio(int $anio): float
    {
        try {
            $sql = "SELECT SUM(monto_total) FROM onat_declaraciones
                    WHERE id_empresa = ? AND modelo IN ('Mensual-Ventas','SC-Trim')
                      AND YEAR(periodo_inicio) = ?
                      AND estado IN ('presentada','pagada')";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->idEmpresa, $anio]);
            return floatval($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    protected function aplicarEscala(float $base): float
    {
        if ($base <= self::MINIMO_EXENTO) return 0.0;
        $impuesto = 0.0;
        $previo = 0.0;
        foreach (self::ESCALA as [$tope, $tasa]) {
            if ($base <= $tope) {
                $impuesto += ($base - $previo) * $tasa;
                return $impuesto;
            }
            $impuesto += ($tope - $previo) * $tasa;
            $previo = $tope;
        }
        return $impuesto;
    }
}
