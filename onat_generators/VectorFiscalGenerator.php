<?php
// onat_generators/VectorFiscalGenerator.php — Vector Fiscal: ficha resumen del contribuyente y obligaciones tributarias activas.

require_once __DIR__ . '/BaseGenerator.php';

class VectorFiscalGenerator extends BaseGenerator
{
    public const MODELO = 'VectorFiscal';
    public const PERIODO_TIPO = 'anual';

    protected const CELL_MAP = [
        'razon_social'             => 'C5',
        'nit'                      => 'C6',
        'tipo_actor'               => 'C7',
        'fecha_inicio_operaciones' => 'C8',
        'municipio'                => 'C9',
        'provincia'                => 'C10',
        'oficina_onat'             => 'C11',
        'ejercicio_fiscal_inicio'  => 'C12',
        'actividades_aprobadas'    => 'C13',
        'representante_nombre'     => 'C15',
        'representante_ci'         => 'C16',
        'representante_cargo'      => 'C17',
        'obligaciones_activas'     => 'C19',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $modelos = modelos_onat_para_actor($this->fiscalRegime['tipo_actor_economico']);
        $obligaciones = array_map(
            fn($m) => $m['codigo'] . ' (' . $m['periodo_tipo'] . ')',
            $modelos
        );

        $actividades = $this->fiscalRegime['actividades_aprobadas'] ?? [];
        if (is_array($actividades)) $actividades = implode(', ', $actividades);

        return [
            'periodo_inicio' => sprintf('%04d-01-01', $anio),
            'periodo_fin'    => sprintf('%04d-12-31', $anio),
            'monto_total'    => 0,
            'datos' => [
                'razon_social'             => $this->config['marca_empresa_nombre'] ?? '',
                'nit'                      => $this->fiscalRegime['nit'] ?? '',
                'tipo_actor'               => $this->fiscalRegime['tipo_actor_economico'] ?? '',
                'fecha_inicio_operaciones' => $this->fiscalRegime['fecha_inicio_operaciones'] ?? '',
                'municipio'                => $this->fiscalRegime['municipio_onat'] ?? '',
                'provincia'                => $this->fiscalRegime['provincia_onat'] ?? '',
                'oficina_onat'             => $this->fiscalRegime['oficina_onat'] ?? '',
                'ejercicio_fiscal_inicio'  => $this->fiscalRegime['ejercicio_fiscal_inicio'] ?? '01-01',
                'actividades_aprobadas'    => $actividades,
                'representante_nombre'     => $this->fiscalRegime['representante_nombre'] ?? '',
                'representante_ci'         => $this->fiscalRegime['representante_ci'] ?? '',
                'representante_cargo'      => $this->fiscalRegime['representante_cargo'] ?? '',
                'obligaciones_activas'     => implode(' | ', $obligaciones),
            ],
        ];
    }
}
