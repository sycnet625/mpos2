<?php
// onat_generators/Dj09Generator.php — DJ-09 Utilidades de Cooperativas No Agropecuarias (anual).

require_once __DIR__ . '/DjUtilidadesGenerator.php';

class Dj09Generator extends DjUtilidadesGenerator
{
    public const MODELO = 'DJ-09';
    public const PERIODO_TIPO = 'anual';

    protected const CELL_MAP = [
        'razon_social'         => 'C5',
        'nit'                  => 'C6',
        'periodo_anio'         => 'C7',
        'municipio'            => 'C8',
        'provincia'            => 'C9',
        'ingresos_brutos'      => 'D14',
        'costo_ventas'         => 'D15',
        'gastos_operativos'    => 'D16',
        'utilidad_fiscal'      => 'D17',
        'tasa_utilidad'        => 'D18',
        'impuesto_utilidades'  => 'D19',
        'representante_nombre' => 'C30',
        'representante_ci'     => 'C31',
        'representante_cargo'  => 'C32',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $base = parent::calcular($anio, $mes);
        // CNoA usa la misma estructura que MIPYME (35%); el DJ-09 es la versión específica
        // del documento ONAT — la tasa puede variar según resoluciones, mantener parametrizado.
        return $base;
    }
}
