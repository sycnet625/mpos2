<?php
// onat_generators/TcpCuotaFijaGenerator.php — Cuota mensual fija TCP régimen simplificado.

require_once __DIR__ . '/BaseGenerator.php';

class TcpCuotaFijaGenerator extends BaseGenerator
{
    public const MODELO = 'TCP-CuotaFija';
    public const PERIODO_TIPO = 'mensual';

    protected const CELL_MAP = [
        'contribuyente_nombre' => 'C5',
        'contribuyente_ci'     => 'C6',
        'periodo_anio'         => 'C7',
        'periodo_mes'          => 'C8',
        'municipio'            => 'C9',
        'cuota_fija'           => 'D14',
        'descuento_digital'    => 'D15',
        'total_a_pagar'        => 'D16',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));

        $cuota = floatval($this->fiscalRegime['cuota_fija_mensual'] ?? 0);
        $descuentoDigital = !empty($this->fiscalRegime['paga_digital']) ? round($cuota * 0.03, 2) : 0;
        $total = round($cuota - $descuentoDigital, 2);

        return [
            'periodo_inicio' => $desde,
            'periodo_fin'    => $hasta,
            'monto_total'    => $total,
            'datos' => [
                'contribuyente_nombre' => $this->fiscalRegime['representante_nombre'] ?: ($this->config['marca_empresa_nombre'] ?? ''),
                'contribuyente_ci'     => $this->fiscalRegime['representante_ci'] ?? '',
                'periodo_anio'         => $anio,
                'periodo_mes'          => sprintf('%02d', $mes),
                'municipio'            => $this->fiscalRegime['municipio_onat'] ?? '',
                'cuota_fija'           => $cuota,
                'descuento_digital'    => $descuentoDigital,
                'total_a_pagar'        => $total,
            ],
        ];
    }
}
