<?php
// onat_generators/ImFuerzaTrabajoGenerator.php — Modelo IM Impuesto sobre el uso de la Fuerza de Trabajo (mensual, 5%).

require_once __DIR__ . '/BaseGenerator.php';

class ImFuerzaTrabajoGenerator extends BaseGenerator
{
    public const MODELO = 'IM-FuerzaTrabajo';
    public const PERIODO_TIPO = 'mensual';

    protected const CELL_MAP = [
        'razon_social'     => 'C5',
        'nit'              => 'C6',
        'periodo_anio'     => 'C7',
        'periodo_mes'      => 'C8',
        'municipio'        => 'C9',
        'nomina_total'     => 'D14',
        'tasa_impuesto'    => 'D15',
        'impuesto_a_pagar' => 'D16',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));

        $nomina = $this->nominaRango($desde, $hasta);
        $tasa = 0.05;
        $impuesto = round($nomina * $tasa, 2);

        return [
            'periodo_inicio' => $desde,
            'periodo_fin'    => $hasta,
            'monto_total'    => $impuesto,
            'datos' => [
                'razon_social'     => $this->config['marca_empresa_nombre'] ?? '',
                'nit'              => $this->fiscalRegime['nit'] ?? '',
                'periodo_anio'     => $anio,
                'periodo_mes'      => sprintf('%02d', $mes),
                'municipio'        => $this->fiscalRegime['municipio_onat'] ?? '',
                'nomina_total'     => round($nomina, 2),
                'tasa_impuesto'    => $tasa,
                'impuesto_a_pagar' => $impuesto,
            ],
        ];
    }

    /** Suma de salarios pagados en el período (categoría salario en gastos_historial / contabilidad). */
    protected function nominaRango(string $desde, string $hasta): float
    {
        try {
            $sql = "SELECT SUM(monto) FROM gastos_historial
                    WHERE fecha BETWEEN ? AND ?
                      AND (categoria LIKE '%SALARIO%' OR categoria LIKE '%NOMINA%' OR categoria LIKE '%PERSONAL%')";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$desde, $hasta]);
            $val = floatval($stmt->fetchColumn() ?: 0);
            if ($val > 0) return $val;
        } catch (Throwable $e) {}

        try {
            $sql = "SELECT SUM(monto) FROM contabilidad_diario
                    WHERE fecha BETWEEN ? AND ?
                      AND (asiento_tipo = 'SALARIO' OR cuenta_debito LIKE '702%' OR cuenta_debito LIKE '60.5%')";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$desde, $hasta]);
            return floatval($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}
