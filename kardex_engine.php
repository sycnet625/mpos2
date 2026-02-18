<?php
// ARCHIVO: kardex_engine.php
// VERSIÓN: 3.2 (SOLUCIÓN DEFINITIVA: MÉTODOS MÁGICOS PARA EVITAR REDECLARACIÓN)
require_once 'db.php';

class KardexEngine {

    protected $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * INTERCEPTOR PARA LLAMADAS DE INSTANCIA ($kardex->registrarMovimiento)
     */
    public function __call($name, $args) {
        if ($name === 'registrarMovimiento') {
            if ($args[0] instanceof PDO) {
                // Estilo estático llamado desde instancia: ($pdo, $sku, $alm, $qty, $tipo, ...)
                return self::_ejecutarLogica(...$args);
            } else {
                // Estilo instancia/CLAUDE.md (sin $pdo): ($sku, $alm, $suc, $tipo, $qty, $ref, $costo, $user, $fecha)
                // Mapeamos a la lógica interna inyectando $this->pdo y reordenando
                $sku   = $args[0] ?? null;
                $alm   = $args[1] ?? null;
                $suc   = $args[2] ?? null;
                $tipo  = $args[3] ?? null;
                $qty   = $args[4] ?? null;
                $ref   = $args[5] ?? null;
                $costo = $args[6] ?? null;
                $user  = $args[7] ?? null;
                $fecha = $args[8] ?? null;

                return self::_ejecutarLogica($this->pdo, $sku, $alm, $qty, $tipo, $ref, $costo, $suc, $fecha);
            }
        }
    }

    /**
     * INTERCEPTOR PARA LLAMADAS ESTÁTICAS (KardexEngine::registrarMovimiento)
     */
    public static function __callStatic($name, $args) {
        if ($name === 'registrarMovimiento') {
            return self::_ejecutarLogica(...$args);
        }
    }

    /**
     * WRAPPER ESPECIAL PARA EL POS (Sigue funcionando igual)
     */
    public function registrarVenta($producto_id, $cantidad, $id_venta, $usuario, $fecha, $almacen_id = 1) {
        $cantidad_salida = -abs(floatval($cantidad));
        return self::_ejecutarLogica($this->pdo, $producto_id, $almacen_id, $cantidad_salida, 'VENTA', "Venta #$id_venta ($usuario)", null, null, $fecha);
    }

    /**
     * LÓGICA CENTRAL ÚNICA (Privada)
     * Firma: ($pdo, $producto_id, $almacen_id, $cantidad, $tipo, $referencia, $costo_unitario, $sucursal_id, $fecha)
     */
    private static function _ejecutarLogica($pdo, $producto_id, $almacen_id, $cantidad, $tipo, $referencia, $costo_unitario = null, $sucursal_id = null, $fecha = null) {
        try {
            if (!$pdo instanceof PDO) throw new Exception("Primer parámetro debe ser una instancia de PDO");
            if (!$fecha) $fecha = date('Y-m-d H:i:s');
            if (!$pdo->inTransaction()) $pdo->beginTransaction();

            $stmtSuc = $pdo->prepare("SELECT id_sucursal FROM almacenes WHERE id = ? LIMIT 1");
            $stmtSuc->execute([$almacen_id]);
            $realSucursal = $stmtSuc->fetchColumn();
            $sucursal_id = $realSucursal ?: ($sucursal_id ?: 1);

            $stmtProd = $pdo->prepare("SELECT costo, precio FROM productos WHERE codigo = ? LIMIT 1");
            $stmtProd->execute([$producto_id]);
            $data_producto = $stmtProd->fetch(PDO::FETCH_ASSOC);

            if (!$data_producto) {
                error_log("Kardex Warning: Producto no encontrado $producto_id");
                return false;
            }

            if ($costo_unitario === null || floatval($costo_unitario) <= 0) {
                $costo_unitario = floatval($data_producto['costo'] ?? 0);
            }

            $stmtSaldo = $pdo->prepare("SELECT saldo_actual FROM kardex WHERE id_producto = ? AND id_almacen = ? ORDER BY id DESC LIMIT 1");
            $stmtSaldo->execute([$producto_id, $almacen_id]);
            $saldo_anterior = floatval($stmtSaldo->fetchColumn() ?: 0);
            $nuevo_saldo = $saldo_anterior + floatval($cantidad);

            $stmtInsert = $pdo->prepare("INSERT INTO kardex (id_producto, id_almacen, fecha, tipo_movimiento, cantidad, saldo_anterior, saldo_actual, referencia, costo_unitario, id_sucursal) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmtInsert->execute([$producto_id, $almacen_id, $fecha, $tipo, $cantidad, $saldo_anterior, $nuevo_saldo, $referencia, $costo_unitario, $sucursal_id]);

            $stmtUpdateStock = $pdo->prepare("INSERT INTO stock_almacen (id_almacen, id_producto, cantidad, id_sucursal, ultima_actualizacion) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE cantidad = ?, ultima_actualizacion = ?");
            $stmtUpdateStock->execute([$almacen_id, $producto_id, $nuevo_saldo, $sucursal_id, $fecha, $nuevo_saldo, $fecha]);

            return true;
        } catch (Exception $e) {
            error_log("Kardex Error: " . $e->getMessage());
            throw $e; 
        }
    }
}
?>