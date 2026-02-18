<?php
// ARCHIVO: debug_history.php
// USO: php debug_history.php [ID_SESION_OPCIONAL]

// 1. Cargar base de datos (ajusta si tu db.php requiere sesión)
require_once 'db.php'; 

echo "\n========================================\n";
echo " DIAGNÓSTICO DE HISTORIAL DE VENTAS\n";
echo "========================================\n";

try {
    // 2. Buscar si hay una CAJA ABIERTA real
    // (Esto simula lo que hace pos2.php al inicio)
    echo "[1] Buscando sesión de caja abierta...\n";
    
    // NOTA: Ajusta '1' y '1' si tu sucursal/usuario son diferentes en la DB
    $id_sucursal = 1; 
    $id_usuario = 1;  // Asumimos usuario ID 1 (Admin) para la prueba

    $stmtSesion = $pdo->prepare("SELECT id, nombre_cajero, fecha_apertura FROM caja_sesiones WHERE estado = 'ABIERTA' ORDER BY id DESC LIMIT 1");
    $stmtSesion->execute();
    $sesion = $stmtSesion->fetch(PDO::FETCH_ASSOC);

    $idSesion = 0;

    if ($sesion) {
        $idSesion = $sesion['id'];
        echo " -> ÉXITO: Caja Abierta encontrada. ID: " . $sesion['id'] . " (Cajero: " . $sesion['nombre_cajero'] . ")\n";
        echo " -> Fecha Apertura: " . $sesion['fecha_apertura'] . "\n";
    } else {
        echo " -> ALERTA: No hay ninguna caja con estado 'ABIERTA' en la base de datos.\n";
        echo " -> El sistema JS recibirá ID 0 y por eso sale vacío.\n";
        
        // Intentar buscar la última cerrada para probar
        $stmtLast = $pdo->query("SELECT id FROM caja_sesiones ORDER BY id DESC LIMIT 1");
        $last = $stmtLast->fetchColumn();
        if ($last) {
            echo " -> Usaremos la última sesión registrada (ID: $last) para probar la consulta.\n";
            $idSesion = $last;
        }
    }

    // Permitir sobreescribir ID desde la línea de comandos
    if (isset($argv[1])) {
        $idSesion = intval($argv[1]);
        echo " -> FORZANDO ID MANUAL: $idSesion\n";
    }

    echo "\n[2] Consultando Ventas para Caja ID: $idSesion...\n";

    // 3. Ejecutar la consulta EXACTA de pos2.php
    $sql = "SELECT id, fecha, total, metodo_pago, cliente_nombre 
            FROM ventas_cabecera 
            WHERE id_caja = ? 
            ORDER BY id DESC";
            
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$idSesion]);
    $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $count = count($tickets);
    echo " -> Resultados encontrados: $count tickets\n";

    if ($count > 0) {
        echo "\n--- PRIMEROS 5 TICKETS ---\n";
        $i = 0;
        foreach ($tickets as $t) {
            if ($i++ >= 5) break;
            echo " ID: " . str_pad($t['id'], 5) . 
                 " | Fecha: " . $t['fecha'] . 
                 " | Total: $" . str_pad($t['total'], 8) . 
                 " | Pago: " . $t['metodo_pago'] . "\n";
        }
    } else {
        echo "\n[!!!] LA CONSULTA DEVUELVE 0 RESULTADOS.\n";
        echo "Posibles causas:\n";
        echo "1. No has hecho ventas desde que abriste ESTA sesión de caja (ID $idSesion).\n";
        echo "2. Las ventas se guardaron con 'id_caja' = 0 o NULL en la tabla 'ventas_cabecera'.\n";
        
        // Diagnóstico extra: Ver si hay ventas huérfanas
        $orphan = $pdo->query("SELECT count(*) FROM ventas_cabecera WHERE id_caja = 0 OR id_caja IS NULL")->fetchColumn();
        echo " -> Ventas huérfanas (sin caja asignada) en DB: $orphan\n";
    }

} catch (Exception $e) {
    echo "ERROR CRÍTICO: " . $e->getMessage() . "\n";
}
echo "\n";

