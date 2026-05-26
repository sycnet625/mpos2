<?php
// onat_generators/SsAporteGenerator.php — Modelo SS Aporte a la Seguridad Social (mensual, 12.5% patronal + 5% especial).

require_once __DIR__ . '/ImFuerzaTrabajoGenerator.php';

class SsAporteGenerator extends ImFuerzaTrabajoGenerator
{
    public const MODELO = 'SS-Aporte';
    public const PERIODO_TIPO = 'mensual';

    protected const CELL_MAP = [
        'razon_social'        => 'C5',
        'nit'                 => 'C6',
        'periodo_anio'        => 'C7',
        'periodo_mes'         => 'C8',
        'municipio'           => 'C9',
        'nomina_total'        => 'D14',
        'tasa_patronal'       => 'D15',
        'aporte_patronal'     => 'D16',
        'tasa_especial'       => 'D17',
        'aporte_especial'     => 'D18',
        'total_a_pagar'       => 'D19',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));

        $nomina = $this->nominaRango($desde, $hasta);
        $tasaPatronal = 0.125; // 12.5% empleador
        $tasaEspecial = 0.05;  // 5% contribución especial trabajador
        $aportePatronal = round($nomina * $tasaPatronal, 2);
        $aporteEspecial = round($nomina * $tasaEspecial, 2);
        $total = round($aportePatronal + $aporteEspecial, 2);

        return [
            'periodo_inicio' => $desde,
            'periodo_fin'    => $hasta,
            'monto_total'    => $total,
            'datos' => [
                'razon_social'    => $this->config['marca_empresa_nombre'] ?? '',
                'nit'             => $this->fiscalRegime['nit'] ?? '',
                'periodo_anio'    => $anio,
                'periodo_mes'     => sprintf('%02d', $mes),
                'municipio'       => $this->fiscalRegime['municipio_onat'] ?? '',
                'nomina_total'    => round($nomina, 2),
                'tasa_patronal'   => $tasaPatronal,
                'aporte_patronal' => $aportePatronal,
                'tasa_especial'   => $tasaEspecial,
                'aporte_especial' => $aporteEspecial,
                'total_a_pagar'   => $total,
            ],
        ];
    }
}
