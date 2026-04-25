<?php
// Helper único para filtrar ventas reales en reportes financieros y contabilidad.
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
