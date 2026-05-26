<?php
// onat_generators/RetencionesGenerator.php — Resumen mensual de retenciones a terceros (5%).

require_once __DIR__ . '/BaseGenerator.php';

class RetencionesGenerator extends BaseGenerator
{
    public const MODELO = 'Retenciones';
    public const PERIODO_TIPO = 'mensual';

    protected const CELL_MAP = [
        'razon_social'     => 'C5',
        'nit'              => 'C6',
        'periodo_anio'     => 'C7',
        'periodo_mes'      => 'C8',
        'cantidad_terceros'=> 'D14',
        'total_bruto'      => 'D15',
        'total_retenido'   => 'D16',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $desde = sprintf('%04d-%02d-01', $anio, $mes);
        $hasta = date('Y-m-t', strtotime($desde));

        try {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) AS n, SUM(monto_bruto) AS bruto, SUM(monto_retenido) AS ret
                                         FROM onat_retenciones
                                         WHERE id_empresa = ? AND anio = ? AND mes = ?");
            $stmt->execute([$this->idEmpresa, $anio, $mes]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            $r = [];
        }
        $cantidad = intval($r['n'] ?? 0);
        $bruto = floatval($r['bruto'] ?? 0);
        $ret = floatval($r['ret'] ?? 0);

        return [
            'periodo_inicio' => $desde,
            'periodo_fin'    => $hasta,
            'monto_total'    => round($ret, 2),
            'datos' => [
                'razon_social'      => $this->config['marca_empresa_nombre'] ?? '',
                'nit'               => $this->fiscalRegime['nit'] ?? '',
                'periodo_anio'      => $anio,
                'periodo_mes'       => sprintf('%02d', $mes),
                'cantidad_terceros' => $cantidad,
                'total_bruto'       => round($bruto, 2),
                'total_retenido'    => round($ret, 2),
            ],
        ];
    }
}
