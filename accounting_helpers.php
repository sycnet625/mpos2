<?php
// Helpers únicos para filtrar ventas reales en reportes financieros y contabilidad.
// Una "venta real" es cualquier fila de ventas_cabecera que NO sea reserva,
// O que sea reserva PERO con estado_pago = 'confirmado'.
// Las reservas con estado_pago != 'confirmado' son intenciones de venta futuras
// y NO deben sumarse en ingresos, ganancias, impuestos ni cierres contables.

if (!function_exists('ventas_reales_where_clause')) {
    /**
     * Devuelve la condición SQL para filtrar ventas reales (excluye reservas no cobradas).
     * Pensada para concatenarse con AND a queries existentes sobre ventas_cabecera.
     *
     * @param string $alias Alias de la tabla ventas_cabecera (ej. 'v', 'vc'). Vacío para sin alias.
     * @return string Cláusula booleana lista para concatenar.
     */
    function ventas_reales_where_clause(string $alias = ''): string {
        $a = $alias !== '' ? "$alias." : '';
        return "({$a}tipo_servicio != 'reserva' OR {$a}estado_pago = 'confirmado')";
    }
}

if (!function_exists('ventas_gastos_casa_where_clause')) {
    /**
     * Identifica tickets cobrados con la opción POS "Tarjeta/Gasto".
     * En la base de datos actual se guarda como "Tarjeta"; instalaciones
     * anteriores pueden guardarlo como "Gasto Casa".
     */
    function ventas_gastos_casa_where_clause(string $alias = ''): string {
        $a = $alias !== '' ? "$alias." : '';
        return "(LOWER(COALESCE({$a}metodo_pago, '')) LIKE '%tarjet%'"
             . " OR LOWER(COALESCE({$a}metodo_pago, '')) LIKE '%gasto%')";
    }
}

if (!function_exists('ventas_ingresos_where_clause')) {
    /**
     * Filtra únicamente ingresos por ventas: excluye reservas pendientes y
     * consumos/gastos de casa. Los tickets de gasto conservan su movimiento
     * de inventario, pero no generan ventas ni ingresos.
     */
    function ventas_ingresos_where_clause(string $alias = ''): string {
        return '(' . ventas_reales_where_clause($alias)
             . ' AND NOT ' . ventas_gastos_casa_where_clause($alias) . ')';
    }
}

if (!function_exists('ticket_es_gasto_casa')) {
    /** Determina si una fila ya cargada corresponde a Tarjeta/Gasto. */
    function ticket_es_gasto_casa(array $ticket): bool {
        $metodo = strtolower(trim((string)($ticket['metodo_pago'] ?? '')));
        return strpos($metodo, 'tarjet') !== false || strpos($metodo, 'gasto') !== false;
    }
}

if (!function_exists('reservas_pendientes_where_clause')) {
    /**
     * Filtra reservas pendientes de cobro (para widgets informativos).
     * Excluye reservas canceladas.
     */
    function reservas_pendientes_where_clause(string $alias = ''): string {
        $a = $alias !== '' ? "$alias." : '';
        return "({$a}tipo_servicio = 'reserva' AND {$a}estado_pago != 'confirmado' AND COALESCE({$a}estado_reserva,'') != 'CANCELADO')";
    }
}
