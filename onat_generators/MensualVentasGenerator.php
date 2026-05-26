<?php
// onat_generators/MensualVentasGenerator.php — Declaración mensual Ventas/Servicios + Contribución Territorial.

require_once __DIR__ . '/BaseGenerator.php';

class MensualVentasGenerator extends BaseGenerator
{
    public const MODELO = 'Mensual-Ventas';
    public const PERIODO_TIPO = 'mensual';

    protected const CELL_MAP = [
        'razon_social'         => 'C5',
        'nit'                  => 'C6',
        'periodo_anio'         => 'C7',
        'periodo_mes'          => 'C8',
        'municipio'            => 'C9',
        'ingresos_brutos'      => 'D14',
        'tasa_ventas'          => 'D15',
        'impuesto_ventas'      => 'D16',
        'tasa_territorial'     => 'D17',
        'contrib_territorial'  => 'D18',
        'total_a_pagar'        => 'D19',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));

        $ingresos = $this->ventasBrutasRango($desde, $hasta);
        $tasaVentas = 0.10;
        $tasaTerritorial = 0.01;
        $impVentas = round($ingresos * $tasaVentas, 2);
        $contribTerr = round($ingresos * $tasaTerritorial, 2);
        $total = round($impVentas + $contribTerr, 2);

        return [
            'periodo_inicio' => $desde,
            'periodo_fin'    => $hasta,
            'monto_total'    => $total,
            'datos' => [
                'razon_social'        => $this->config['marca_empresa_nombre'] ?? '',
                'nit'                 => $this->fiscalRegime['nit'] ?? '',
                'periodo_anio'        => $anio,
                'periodo_mes'         => sprintf('%02d', $mes),
                'municipio'           => $this->fiscalRegime['municipio_onat'] ?? '',
                'ingresos_brutos'     => round($ingresos, 2),
                'tasa_ventas'         => $tasaVentas,
                'impuesto_ventas'     => $impVentas,
                'tasa_territorial'    => $tasaTerritorial,
                'contrib_territorial' => $contribTerr,
                'total_a_pagar'       => $total,
            ],
        ];
    }
}
