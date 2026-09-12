<?php
// ARCHIVO: kardex_engine.php
// VERSIÓN: 3.3 (CORRECCIÓN: REGISTRO DE USUARIO EN KARDEX)
require_once 'db.php';

class KardexEngine {

    protected $pdo;

    /** Comprueba la columna sin asumir una versión concreta del esquema. */
    private static function tableHasColumn(PDO $pdo, string $table, string $column): bool {
        // MySQL/MariaDB no acepta marcadores de parámetros en SHOW COLUMNS.
        // Tabla y columna provienen de código interno; la columna se escapa
        // como literal para evitar el SQL 1064 durante el cobro/kardex.
        $safeTable = str_replace('`', '``', $table);
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$safeTable}` LIKE " . $pdo->quote($column));
        return (bool)$stmt->fetch(PDO::FETCH_ASSOC);
    }

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
                $empresa = $args[9] ?? null;

                return self::_ejecutarLogica($this->pdo, $sku, $alm, $qty, $tipo, $ref, $costo, $suc, $fecha, $user, $empresa);
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
    public function registrarVenta($producto_id, $cantidad, $id_venta, $usuario, $fecha, $almacen_id = 1, $sucursal_id = null, $empresa_id = null) {
        $cantidad_salida = -abs(floatval($cantidad));
        return self::_ejecutarLogica($this->pdo, $producto_id, $almacen_id, $cantidad_salida, 'VENTA', "Venta #$id_venta", null, $sucursal_id, $fecha, $usuario, $empresa_id);
    }

    /**
     * LÓGICA CENTRAL ÚNICA (Privada)
     * Firma actualizada para incluir $usuario
     */
    private static function _ejecutarLogica($pdo, $producto_id, $almacen_id, $cantidad, $tipo, $referencia, $costo_unitario = null, $sucursal_id = null, $fecha = null, $usuario = null, $empresa_id = null) {
        try {
            if (!$pdo instanceof PDO) throw new Exception("Primer parámetro debe ser una instancia de PDO");
            if (!$fecha) $fecha = date('Y-m-d H:i:s');
            $startedTx = false;
            if (!$pdo->inTransaction()) {
                $pdo->beginTransaction();
                $startedTx = true;
            }

            $stmtSuc = $pdo->prepare("SELECT id_sucursal FROM almacenes WHERE id = ? LIMIT 1");
            $stmtSuc->execute([$almacen_id]);
            $realSucursal = $stmtSuc->fetchColumn();
            $sucursal_id = $realSucursal ?: ($sucursal_id ?: 1);

            if ($empresa_id !== null && (int)$empresa_id > 0) {
                $stmtProd = $pdo->prepare("SELECT costo, precio, id_empresa FROM productos WHERE codigo = ? AND id_empresa = ? AND activo = 1 LIMIT 1");
                $stmtProd->execute([$producto_id, (int)$empresa_id]);
            } else {
                $stmtProd = $pdo->prepare("SELECT costo, precio, id_empresa FROM productos WHERE codigo = ? AND activo = 1 LIMIT 1");
                $stmtProd->execute([$producto_id]);
            }
            $data_producto = $stmtProd->fetch(PDO::FETCH_ASSOC);

            if (!$data_producto) {
                throw new Exception("Producto no encontrado o fuera de la empresa en kardex: {$producto_id}");
            }

            // Las llamadas antiguas no pasan empresa; el producto sigue siendo la
            // fuente fiable para mantener compatibilidad con esos flujos.
            $empresa_id = (int)($empresa_id ?: ($data_producto['id_empresa'] ?? 1));

            if ($costo_unitario === null || floatval($costo_unitario) <= 0) {
                $costo_unitario = floatval($data_producto['costo'] ?? 0);
            }

            $saldoWhere = ['id_producto = ?', 'id_almacen = ?'];
            $saldoParams = [$producto_id, $almacen_id];
            if (self::tableHasColumn($pdo, 'kardex', 'id_sucursal')) {
                $saldoWhere[] = 'id_sucursal = ?';
                $saldoParams[] = $sucursal_id;
            }
            if (self::tableHasColumn($pdo, 'kardex', 'id_empresa')) {
                $saldoWhere[] = 'id_empresa = ?';
                $saldoParams[] = $empresa_id;
            }
            $stmtSaldo = $pdo->prepare("SELECT saldo_actual FROM kardex WHERE " . implode(' AND ', $saldoWhere) . " ORDER BY id DESC LIMIT 1 FOR UPDATE");
            $stmtSaldo->execute($saldoParams);
            $saldo_anterior = floatval($stmtSaldo->fetchColumn() ?: 0);
            $nuevo_saldo = $saldo_anterior + floatval($cantidad);

            if (self::tableHasColumn($pdo, 'kardex', 'id_empresa')) {
                $stmtInsert = $pdo->prepare("INSERT INTO kardex (id_producto, id_almacen, fecha, tipo_movimiento, cantidad, saldo_anterior, saldo_actual, referencia, costo_unitario, id_sucursal, id_empresa, usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtInsert->execute([$producto_id, $almacen_id, $fecha, $tipo, $cantidad, $saldo_anterior, $nuevo_saldo, $referencia, $costo_unitario, $sucursal_id, $empresa_id, $usuario]);
            } else {
                $stmtInsert = $pdo->prepare("INSERT INTO kardex (id_producto, id_almacen, fecha, tipo_movimiento, cantidad, saldo_anterior, saldo_actual, referencia, costo_unitario, id_sucursal, usuario) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $stmtInsert->execute([$producto_id, $almacen_id, $fecha, $tipo, $cantidad, $saldo_anterior, $nuevo_saldo, $referencia, $costo_unitario, $sucursal_id, $usuario]);
            }

            $stmtUpdateStock = $pdo->prepare("INSERT INTO stock_almacen (id_almacen, id_producto, cantidad, id_sucursal, ultima_actualizacion) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE cantidad = ?, ultima_actualizacion = ?");
            $stmtUpdateStock->execute([$almacen_id, $producto_id, $nuevo_saldo, $sucursal_id, $fecha, $nuevo_saldo, $fecha]);

            if ($startedTx) $pdo->commit();
            return true;
        } catch (Exception $e) {
            error_log("Kardex Error: " . $e->getMessage());
            if (isset($startedTx) && $startedTx && $pdo instanceof PDO && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e; 
        }
    }
}
?>
