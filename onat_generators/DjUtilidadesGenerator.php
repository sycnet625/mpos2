<?php
// onat_generators/DjUtilidadesGenerator.php — DJ Utilidades MIPYME (anual, 35%).

require_once __DIR__ . '/BaseGenerator.php';

class DjUtilidadesGenerator extends BaseGenerator
{
    public const MODELO = 'DJ-Utilidades';
    public const PERIODO_TIPO = 'anual';

    /**
     * Coordenadas dentro de la plantilla oficial DJ-Utilidades.xlsx.
     * Calibrar al colocar la plantilla real (placeholders por ahora — el fallback
     * sigue funcionando aunque no estén ajustadas).
     */
    protected const CELL_MAP = [
        'razon_social'           => 'C5',
        'nit'                    => 'C6',
        'periodo_anio'           => 'C7',
        'municipio'              => 'C8',
        'provincia'              => 'C9',
        'ingresos_brutos'        => 'D14',
        'inventario_inicial'     => 'D15',
        'compras_periodo'        => 'D16',
        'inventario_final'       => 'D17',
        'costo_ventas'           => 'D18',
        'gastos_operativos'      => 'D19',
        'utilidad_fiscal'        => 'D20',
        'tasa_utilidad'          => 'D21',
        'impuesto_calculado'     => 'D22',
        'anticipos_trimestrales' => 'D23',
        'descuento_anticipado'   => 'D24',
        'descuento_digital'      => 'D25',
        'impuesto_a_pagar'       => 'D26',
        'representante_nombre'   => 'C30',
        'representante_ci'       => 'C31',
        'representante_cargo'    => 'C32',
    ];

    public function calcular(int $anio, int $mes): array
    {
        $desde = sprintf('%04d-01-01', $anio);
        $hasta = sprintf('%04d-12-31', $anio);

        $ingresos = $this->ventasBrutasRango($desde, $hasta);
        $costo    = $this->costoVentasRango($desde, $hasta);
        $gastos   = $this->gastosOperativosRango($desde, $hasta);

        // Inventarios fiscales (inicial y final del ejercicio).
        $invInicial = $this->valorInventarioEnFecha(sprintf('%04d-01-01', $anio));
        $invFinal   = $this->valorInventarioEnFecha(sprintf('%04d-12-31', $anio));
        $compras    = $this->comprasRango($desde, $hasta);
        // Costo de ventas reconciliado: si tenemos inventarios, usamos la fórmula contable.
        if ($invInicial > 0 || $invFinal > 0) {
            $costoReconciliado = max(0.0, $invInicial + $compras - $invFinal);
            // Preferimos el valor reconciliado si difiere razonablemente.
            if ($costoReconciliado > 0) $costo = $costoReconciliado;
        }

        $utilidadFiscal = max(0.0, $ingresos - $costo - $gastos);
        $tasa = 0.35;
        $impuestoCalculado = round($utilidadFiscal * $tasa, 2);

        // Anticipos trimestrales ya pagados/presentados.
        $anticipos = $this->anticiposTrimestrales($anio);
        $impuestoNeto = max(0.0, $impuestoCalculado - $anticipos);

        $hoy = new DateTimeImmutable('today');
        $corte = new DateTimeImmutable(sprintf('%04d-02-28', $anio + 1));
        $descAnticipado = ($hoy <= $corte) ? round($impuestoNeto * 0.05, 2) : 0.0;
        $descDigital = !empty($this->fiscalRegime['paga_digital']) ? round($impuestoNeto * 0.03, 2) : 0.0;
        $impuestoFinal = max(0.0, round($impuestoNeto - $descAnticipado - $descDigital, 2));

        return [
            'periodo_inicio' => $desde,
            'periodo_fin'    => $hasta,
            'monto_total'    => $impuestoFinal,
            'datos' => [
                'razon_social'           => $this->config['marca_empresa_nombre'] ?? '',
                'nit'                    => $this->fiscalRegime['nit'] ?? '',
                'periodo_anio'           => $anio,
                'municipio'              => $this->fiscalRegime['municipio_onat'] ?? '',
                'provincia'              => $this->fiscalRegime['provincia_onat'] ?? '',
                'ingresos_brutos'        => round($ingresos, 2),
                'inventario_inicial'     => round($invInicial, 2),
                'compras_periodo'        => round($compras, 2),
                'inventario_final'       => round($invFinal, 2),
                'costo_ventas'           => round($costo, 2),
                'gastos_operativos'      => round($gastos, 2),
                'utilidad_fiscal'        => round($utilidadFiscal, 2),
                'tasa_utilidad'          => $tasa,
                'impuesto_calculado'     => $impuestoCalculado,
                'anticipos_trimestrales' => round($anticipos, 2),
                'descuento_anticipado'   => $descAnticipado,
                'descuento_digital'      => $descDigital,
                'impuesto_a_pagar'       => $impuestoFinal,
                'representante_nombre'   => $this->fiscalRegime['representante_nombre'] ?? '',
                'representante_ci'       => $this->fiscalRegime['representante_ci'] ?? '',
                'representante_cargo'    => $this->fiscalRegime['representante_cargo'] ?? '',
            ],
        ];
    }

    /**
     * Valor del inventario a una fecha dada (cantidad × costo, almacenes de la empresa).
     * Snapshot rudimentario basado en stock_almacen actual ajustado por kardex.
     */
    protected function valorInventarioEnFecha(string $fecha): float
    {
        try {
            // Snapshot exacto: stock actual menos movimientos posteriores a la fecha.
            $sql = "SELECT SUM(s.cantidad * COALESCE(p.costo, 0)) AS valor_actual
                    FROM stock_almacen s
                    JOIN almacenes a ON a.id = s.id_almacen
                    JOIN productos p ON p.codigo = s.id_producto
                    WHERE a.id_empresa = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$this->idEmpresa]);
            $valorActual = floatval($stmt->fetchColumn() ?: 0);

            // Ajuste: restar entradas posteriores y sumar salidas posteriores a la fecha.
            $sql2 = "SELECT
                        COALESCE(SUM(CASE WHEN k.tipo_movimiento IN ('ENTRADA','COMPRA','AJUSTE+') THEN k.cantidad * COALESCE(k.costo_unitario, p.costo, 0) ELSE 0 END), 0) AS entradas_post,
                        COALESCE(SUM(CASE WHEN k.tipo_movimiento IN ('VENTA','SALIDA','MERMA','AJUSTE-','DEVOLUCION') THEN k.cantidad * COALESCE(k.costo_unitario, p.costo, 0) ELSE 0 END), 0) AS salidas_post
                     FROM kardex k
                     JOIN almacenes a ON a.id = k.id_almacen
                     JOIN productos p ON p.codigo = k.id_producto
                     WHERE a.id_empresa = ? AND k.fecha > ?";
            $stmt2 = $this->pdo->prepare($sql2);
            $stmt2->execute([$this->idEmpresa, $fecha]);
            $row = $stmt2->fetch(PDO::FETCH_ASSOC) ?: ['entradas_post' => 0, 'salidas_post' => 0];

            return max(0.0, $valorActual - floatval($row['entradas_post']) + floatval($row['salidas_post']));
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    protected function comprasRango(string $desde, string $hasta): float
    {
        try {
            $stmt = $this->pdo->prepare("SELECT SUM(total) FROM compras_cabecera
                                         WHERE id_empresa = ? AND DATE(fecha) BETWEEN ? AND ?
                                           AND estado = 'APLICADA'");
            $stmt->execute([$this->idEmpresa, $desde, $hasta]);
            return floatval($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }

    protected function anticiposTrimestrales(int $anio): float
    {
        try {
            $stmt = $this->pdo->prepare("SELECT SUM(monto_total) FROM onat_declaraciones
                                         WHERE id_empresa = ? AND modelo = 'SC-Trim'
                                           AND YEAR(periodo_inicio) = ?
                                           AND estado IN ('presentada','pagada')");
            $stmt->execute([$this->idEmpresa, $anio]);
            return floatval($stmt->fetchColumn() ?: 0);
        } catch (Throwable $e) {
            return 0.0;
        }
    }
}
