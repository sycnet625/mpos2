<?php
// empresa_fiscal_helpers.php — Acceso a datos de régimen fiscal (tabla empresas_fiscal)
// Requiere $pdo de db.php cargado previamente.

if (!function_exists('empresa_fiscal_defaults')) {
    function empresa_fiscal_defaults(int $id_empresa): array {
        return [
            'id_empresa'               => $id_empresa,
            'tipo_actor_economico'     => 'MIPYME',
            'nit'                      => '',
            'regimen_simplificado'     => 0,
            'actividades_aprobadas'    => [],
            'fecha_inicio_operaciones' => null,
            'municipio_onat'           => '',
            'provincia_onat'           => '',
            'oficina_onat'             => '',
            'representante_nombre'     => '',
            'representante_ci'         => '',
            'representante_cargo'      => '',
            'ejercicio_fiscal_inicio'  => '01-01',
            'cuota_fija_mensual'         => 0,
            'tope_gastos_deducibles_pct' => 80.0,
            'paga_digital'               => 1,
        ];
    }
}

if (!function_exists('get_empresa_fiscal')) {
    function get_empresa_fiscal(int $id_empresa): array {
        global $pdo;
        $defaults = empresa_fiscal_defaults($id_empresa);
        try {
            $stmt = $pdo->prepare("SELECT * FROM empresas_fiscal WHERE id_empresa = ? LIMIT 1");
            $stmt->execute([$id_empresa]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) return $defaults;
            $row['actividades_aprobadas'] = $row['actividades_aprobadas']
                ? (json_decode($row['actividades_aprobadas'], true) ?: [])
                : [];
            $row['regimen_simplificado'] = intval($row['regimen_simplificado']);
            return array_merge($defaults, $row);
        } catch (Exception $e) {
            return $defaults;
        }
    }
}

if (!function_exists('save_empresa_fiscal')) {
    function save_empresa_fiscal(int $id_empresa, array $data): bool {
        global $pdo;
        $tipos_validos = ['TCP','MIPYME','CNoA','PersonaNatural'];
        $tipo = in_array($data['tipo_actor_economico'] ?? '', $tipos_validos, true)
            ? $data['tipo_actor_economico'] : 'MIPYME';

        $actividades = $data['actividades_aprobadas'] ?? [];
        if (is_string($actividades)) {
            $decoded = json_decode($actividades, true);
            $actividades = is_array($decoded) ? $decoded : array_filter(array_map('trim', explode(',', $actividades)));
        }
        $actividadesJson = json_encode(array_values($actividades), JSON_UNESCAPED_UNICODE);

        $fechaInicio = !empty($data['fecha_inicio_operaciones']) ? $data['fecha_inicio_operaciones'] : null;

        $sql = "INSERT INTO empresas_fiscal
            (id_empresa, tipo_actor_economico, nit, regimen_simplificado, actividades_aprobadas,
             fecha_inicio_operaciones, municipio_onat, provincia_onat, oficina_onat,
             representante_nombre, representante_ci, representante_cargo, ejercicio_fiscal_inicio,
             cuota_fija_mensual, tope_gastos_deducibles_pct, paga_digital)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                tipo_actor_economico=VALUES(tipo_actor_economico),
                nit=VALUES(nit),
                regimen_simplificado=VALUES(regimen_simplificado),
                actividades_aprobadas=VALUES(actividades_aprobadas),
                fecha_inicio_operaciones=VALUES(fecha_inicio_operaciones),
                municipio_onat=VALUES(municipio_onat),
                provincia_onat=VALUES(provincia_onat),
                oficina_onat=VALUES(oficina_onat),
                representante_nombre=VALUES(representante_nombre),
                representante_ci=VALUES(representante_ci),
                representante_cargo=VALUES(representante_cargo),
                ejercicio_fiscal_inicio=VALUES(ejercicio_fiscal_inicio),
                cuota_fija_mensual=VALUES(cuota_fija_mensual),
                tope_gastos_deducibles_pct=VALUES(tope_gastos_deducibles_pct),
                paga_digital=VALUES(paga_digital)";

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([
            $id_empresa,
            $tipo,
            trim((string)($data['nit'] ?? '')),
            !empty($data['regimen_simplificado']) ? 1 : 0,
            $actividadesJson,
            $fechaInicio,
            trim((string)($data['municipio_onat'] ?? '')),
            trim((string)($data['provincia_onat'] ?? '')),
            trim((string)($data['oficina_onat'] ?? '')),
            trim((string)($data['representante_nombre'] ?? '')),
            trim((string)($data['representante_ci'] ?? '')),
            trim((string)($data['representante_cargo'] ?? '')),
            substr(trim((string)($data['ejercicio_fiscal_inicio'] ?? '01-01')), 0, 5) ?: '01-01',
            floatval($data['cuota_fija_mensual'] ?? 0),
            floatval($data['tope_gastos_deducibles_pct'] ?? 80),
            !empty($data['paga_digital']) ? 1 : 0,
        ]);
    }
}

if (!function_exists('modelos_onat_para_actor')) {
    /**
     * Devuelve los modelos ONAT aplicables a un tipo de actor económico.
     * Cada item: ['codigo','nombre','periodo_tipo','descripcion'].
     */
    function modelos_onat_para_actor(string $tipo_actor, bool $regimen_simplificado = false): array {
        $todos = [
            'DJ-08' => [
                'codigo'       => 'DJ-08',
                'nombre'       => 'DJ Ingresos Personales',
                'periodo_tipo' => 'anual',
                'descripcion'  => 'Declaración Jurada del Impuesto sobre Ingresos Personales (escala progresiva).',
            ],
            'DJ-Utilidades' => [
                'codigo'       => 'DJ-Utilidades',
                'nombre'       => 'DJ Utilidades MIPYME',
                'periodo_tipo' => 'anual',
                'descripcion'  => 'Declaración Jurada anual del Impuesto sobre Utilidades (35%).',
            ],
            'SC-Trim' => [
                'codigo'       => 'SC-Trim',
                'nombre'       => 'Anticipo Trimestral Utilidades',
                'periodo_tipo' => 'trimestral',
                'descripcion'  => 'Pago a cuenta del Impuesto sobre Utilidades (primeros 20 días post-trimestre).',
            ],
            'Mensual-Ventas' => [
                'codigo'       => 'Mensual-Ventas',
                'nombre'       => 'Declaración Mensual Ventas + Territorial',
                'periodo_tipo' => 'mensual',
                'descripcion'  => 'Impuesto sobre Ventas/Servicios y Contribución Territorial.',
            ],
            'IM-FuerzaTrabajo' => [
                'codigo'       => 'IM-FuerzaTrabajo',
                'nombre'       => 'Modelo IM Fuerza de Trabajo',
                'periodo_tipo' => 'mensual',
                'descripcion'  => 'Impuesto por uso de la fuerza de trabajo (5% sobre nómina).',
            ],
            'SS-Aporte' => [
                'codigo'       => 'SS-Aporte',
                'nombre'       => 'Modelo SS Seguridad Social',
                'periodo_tipo' => 'mensual',
                'descripcion'  => 'Aporte a la Seguridad Social (12.5% patronal + 5% especial).',
            ],
            'DJ-09' => [
                'codigo'       => 'DJ-09',
                'nombre'       => 'DJ-09 Utilidades CNoA',
                'periodo_tipo' => 'anual',
                'descripcion'  => 'Declaración Jurada anual del Impuesto sobre Utilidades — Cooperativas No Agropecuarias.',
            ],
            'VectorFiscal' => [
                'codigo'       => 'VectorFiscal',
                'nombre'       => 'Vector Fiscal',
                'periodo_tipo' => 'anual',
                'descripcion'  => 'Ficha resumen del contribuyente con obligaciones tributarias activas.',
            ],
            'TCP-CuotaFija' => [
                'codigo'       => 'TCP-CuotaFija',
                'nombre'       => 'Cuota Fija TCP',
                'periodo_tipo' => 'mensual',
                'descripcion'  => 'Pago de cuota mensual fija — TCP régimen simplificado.',
            ],
            'Retenciones' => [
                'codigo'       => 'Retenciones',
                'nombre'       => 'Retenciones a Terceros',
                'periodo_tipo' => 'mensual',
                'descripcion'  => 'Retenciones aplicadas a artistas, profesionales y servicios contratados.',
            ],
        ];

        switch ($tipo_actor) {
            case 'TCP':
            case 'PersonaNatural':
                if ($regimen_simplificado) {
                    return [
                        $todos['TCP-CuotaFija'],
                        $todos['VectorFiscal'],
                    ];
                }
                return [
                    $todos['DJ-08'],
                    $todos['Mensual-Ventas'],
                    $todos['IM-FuerzaTrabajo'],
                    $todos['SS-Aporte'],
                    $todos['Retenciones'],
                    $todos['VectorFiscal'],
                ];
            case 'CNoA':
                return [
                    $todos['DJ-09'],
                    $todos['SC-Trim'],
                    $todos['Mensual-Ventas'],
                    $todos['IM-FuerzaTrabajo'],
                    $todos['SS-Aporte'],
                    $todos['Retenciones'],
                    $todos['DJ-08'], // socios cooperativistas sobre anticipos personales
                    $todos['VectorFiscal'],
                ];
            case 'MIPYME':
            default:
                return [
                    $todos['DJ-Utilidades'],
                    $todos['SC-Trim'],
                    $todos['Mensual-Ventas'],
                    $todos['IM-FuerzaTrabajo'],
                    $todos['SS-Aporte'],
                    $todos['Retenciones'],
                    $todos['DJ-08'], // Para socios sobre dividendos.
                    $todos['VectorFiscal'],
                ];
        }
    }
}
