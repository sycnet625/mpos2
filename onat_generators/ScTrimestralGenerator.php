<?php
// onat_generators/ScTrimestralGenerator.php — Anticipo trimestral del Impuesto sobre Utilidades.

require_once __DIR__ . '/BaseGenerator.php';

class ScTrimestralGenerator extends BaseGenerator
{
    public const MODELO = 'SC-Trim';
    public const PERIODO_TIPO = 'trimestral';

    protected const CELL_MAP = [
        'razon_social'        => 'C5',
        'nit'                 => 'C6',
        'periodo_anio'        => 'C7',
        'periodo_trimestre'   => 'C8',
        'ingresos_brutos'     => 'D14',
        'costo_ventas'        => 'D15',
        'gastos_operativos'   => 'D16',
        'utilidad_estimada'   => 'D17',
        'tasa_anticipo'       => 'D18',
        'anticipo_a_pagar'    => 'D19',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $trimestre = (int)ceil($mes / 3);
        $mesInicio = ($trimestre - 1) * 3 + 1;
        $desde = sprintf('%04d-%02d-01', $anio, $mesInicio);
        $hasta = date('Y-m-t', strtotime(sprintf('%04d-%02d-01', $anio, $mesInicio + 2)));

        $ingresos = $this->ventasBrutasRango($desde, $hasta);
        $costo    = $this->costoVentasRango($desde, $hasta);
        $gastos   = $this->gastosOperativosRango($desde, $hasta);
        $utilidad = max(0.0, $ingresos - $costo - $gastos);
        $tasa = 0.35;
        $anticipo = round($utilidad * $tasa, 2);

        return [
            'periodo_inicio' => $desde,
            'periodo_fin'    => $hasta,
            'monto_total'    => $anticipo,
            'datos' => [
                'razon_social'      => $this->config['marca_empresa_nombre'] ?? '',
                'nit'               => $this->fiscalRegime['nit'] ?? '',
                'periodo_anio'      => $anio,
                'periodo_trimestre' => 'T' . $trimestre,
                'ingresos_brutos'   => round($ingresos, 2),
                'costo_ventas'      => round($costo, 2),
                'gastos_operativos' => round($gastos, 2),
                'utilidad_estimada' => round($utilidad, 2),
                'tasa_anticipo'     => $tasa,
                'anticipo_a_pagar'  => $anticipo,
            ],
        ];
    }
}
