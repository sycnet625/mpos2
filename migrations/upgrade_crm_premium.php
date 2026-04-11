<?php
// ARCHIVO: upgrade_crm_premium.php
// DESCRIPCIÓN: Migración para upgrade de CRM a nivel Premium
// - Agrega columnas a tabla clientes (tipo_cliente, ruc, contacto_principal, giro_negocio)
// - Crea tablas: clientes_telefonos, clientes_direcciones
// - Migra datos existentes
// - Establece FK con CASCADE

require_once __DIR__ . '/../db.php';

try {
    echo "<h3>🚀 Iniciando Upgrade CRM Premium...</h3>";

    // =============================================================
    // PASO 1: Crear/Verificar tabla clientes_telefonos
    // =============================================================
    echo "<p>📱 Creando tabla <b>clientes_telefonos</b>...</p>";

    $sqlTelefonos = "CREATE TABLE IF NOT EXISTS `clientes_telefonos` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `id_cliente` INT NOT NULL,
        `tipo` ENUM('Celular', 'Fijo', 'WhatsApp', 'Comercial') DEFAULT 'Celular',
        `numero` VARCHAR(50) NOT NULL,
        `es_principal` TINYINT(1) DEFAULT 0,
        `fecha_agregado` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `activo` TINYINT(1) DEFAULT 1,
        FOREIGN KEY (`id_cliente`) REFERENCES `clientes`(`id`) ON DELETE CASCADE,
        KEY `idx_cliente` (`id_cliente`),
        KEY `idx_numero` (`numero`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sqlTelefonos);
    echo "✅ Tabla <b>clientes_telefonos</b> creada o verificada.<br>";

    // =============================================================
    // PASO 2: Crear/Verificar tabla clientes_direcciones
    // =============================================================
    echo "<p>🏠 Creando tabla <b>clientes_direcciones</b>...</p>";

    $sqlDirecciones = "CREATE TABLE IF NOT EXISTS `clientes_direcciones` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `id_cliente` INT NOT NULL,
        `tipo` ENUM('Entrega', 'Facturación', 'Comercial', 'Almacén') DEFAULT 'Entrega',
        `calle` VARCHAR(100) NOT NULL,
        `numero` VARCHAR(20) DEFAULT NULL,
        `apartamento` VARCHAR(50) DEFAULT NULL,
        `reparto` VARCHAR(100) DEFAULT NULL,
        `ciudad` VARCHAR(100) DEFAULT NULL,
        `codigo_postal` VARCHAR(20) DEFAULT NULL,
        `direccion_completa` VARCHAR(255) GENERATED ALWAYS AS (
            CONCAT_WS(', ', `calle`, `numero`, `apartamento`, `reparto`, `ciudad`)
        ) STORED,
        `es_principal` TINYINT(1) DEFAULT 0,
        `instrucciones` TEXT DEFAULT NULL,
        `fecha_agregada` DATETIME DEFAULT CURRENT_TIMESTAMP,
        `activo` TINYINT(1) DEFAULT 1,
        FOREIGN KEY (`id_cliente`) REFERENCES `clientes`(`id`) ON DELETE CASCADE,
        KEY `idx_cliente` (`id_cliente`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;";

    $pdo->exec($sqlDirecciones);
    echo "✅ Tabla <b>clientes_direcciones</b> creada o verificada.<br>";

    // =============================================================
    // PASO 3: Agregar columnas nuevas a tabla clientes
    // =============================================================
    echo "<p>👤 Verificando columnas en tabla <b>clientes</b>...</p>";

    $columnasAgregar = [
        'tipo_cliente' => "ALTER TABLE `clientes` ADD COLUMN `tipo_cliente` ENUM('Persona', 'Negocio') DEFAULT 'Persona' AFTER `activo`",
        'ruc' => "ALTER TABLE `clientes` ADD COLUMN `ruc` VARCHAR(50) DEFAULT NULL AFTER `nit_ci`",
        'contacto_principal' => "ALTER TABLE `clientes` ADD COLUMN `contacto_principal` VARCHAR(150) DEFAULT NULL AFTER `ruc`",
        'giro_negocio' => "ALTER TABLE `clientes` ADD COLUMN `giro_negocio` VARCHAR(150) DEFAULT NULL AFTER `contacto_principal`",
        'telefono_principal' => "ALTER TABLE `clientes` ADD COLUMN `telefono_principal` VARCHAR(50) DEFAULT NULL AFTER `giro_negocio`",
        'direccion_principal' => "ALTER TABLE `clientes` ADD COLUMN `direccion_principal` VARCHAR(255) DEFAULT NULL AFTER `telefono_principal`"
    ];

    foreach ($columnasAgregar as $nombreCol => $sqlAdd) {
        $checkCol = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'clientes' AND COLUMN_NAME = ?");
        $checkCol->execute([$nombreCol]);
        $existe = (int)$checkCol->fetchColumn() > 0;

        if (!$existe) {
            echo "  ➕ Agregando columna <b>$nombreCol</b>...";
            $pdo->exec($sqlAdd);
            echo " ✅<br>";
        } else {
            echo "  ✓ Columna <b>$nombreCol</b> ya existe.<br>";
        }
    }

    // =============================================================
    // PASO 4: Migrar datos existentes (telefono → clientes_telefonos)
    // =============================================================
    echo "<p>📞 Migrando teléfonos existentes...</p>";

    $stmtSelectTels = $pdo->prepare("SELECT id, telefono FROM clientes WHERE telefono IS NOT NULL AND telefono != '' AND id NOT IN (SELECT DISTINCT id_cliente FROM clientes_telefonos)");
    $stmtSelectTels->execute();
    $clientesConTel = $stmtSelectTels->fetchAll(PDO::FETCH_ASSOC);

    if (count($clientesConTel) > 0) {
        $stmtInsertTel = $pdo->prepare("INSERT INTO clientes_telefonos (id_cliente, tipo, numero, es_principal) VALUES (?, 'Celular', ?, 1)");
        foreach ($clientesConTel as $cli) {
            $stmtInsertTel->execute([$cli['id'], $cli['telefono']]);
        }
        echo "✅ " . count($clientesConTel) . " teléfonos migrados.<br>";
    } else {
        echo "✓ No hay teléfonos nuevos para migrar.<br>";
    }

    // =============================================================
    // PASO 5: Migrar datos existentes (direccion → clientes_direcciones)
    // =============================================================
    echo "<p>🏘️ Migrando direcciones existentes...</p>";

    $stmtSelectDirs = $pdo->prepare("SELECT id, direccion FROM clientes WHERE direccion IS NOT NULL AND direccion != '' AND id NOT IN (SELECT DISTINCT id_cliente FROM clientes_direcciones)");
    $stmtSelectDirs->execute();
    $clientesConDir = $stmtSelectDirs->fetchAll(PDO::FETCH_ASSOC);

    if (count($clientesConDir) > 0) {
        $stmtInsertDir = $pdo->prepare("INSERT INTO clientes_direcciones (id_cliente, tipo, calle, es_principal) VALUES (?, 'Entrega', ?, 1)");
        foreach ($clientesConDir as $cli) {
            $stmtInsertDir->execute([$cli['id'], $cli['direccion']]);
        }
        echo "✅ " . count($clientesConDir) . " direcciones migradas.<br>";
    } else {
        echo "✓ No hay direcciones nuevas para migrar.<br>";
    }

    // =============================================================
    // PASO 6: Actualizar campos principales en clientes
    // =============================================================
    echo "<p>🔄 Actualizando referencias en tabla clientes...</p>";

    // Copiar teléfono principal a campo de compatibilidad
    $pdo->exec("UPDATE clientes c SET telefono_principal = (
        SELECT numero FROM clientes_telefonos
        WHERE id_cliente = c.id AND es_principal = 1 AND activo = 1
        LIMIT 1
    ) WHERE telefono_principal IS NULL");

    // Copiar dirección principal a campo de compatibilidad
    $pdo->exec("UPDATE clientes c SET direccion_principal = (
        SELECT direccion_completa FROM clientes_direcciones
        WHERE id_cliente = c.id AND es_principal = 1 AND activo = 1
        LIMIT 1
    ) WHERE direccion_principal IS NULL");

    echo "✅ Campos de compatibilidad actualizados.<br>";

    // =============================================================
    // RESUMEN
    // =============================================================
    echo "<hr>";
    echo "<h4>🎉 ¡Upgrade CRM Premium completado exitosamente!</h4>";
    echo "<p>Se han realizado los siguientes cambios:</p>";
    echo "<ul>";
    echo "<li>✅ Tabla <b>clientes_telefonos</b> creada</li>";
    echo "<li>✅ Tabla <b>clientes_direcciones</b> creada</li>";
    echo "<li>✅ Columnas nuevas agregadas a <b>clientes</b>: tipo_cliente, ruc, contacto_principal, giro_negocio</li>";
    echo "<li>✅ Datos migrados: teléfonos y direcciones existentes</li>";
    echo "<li>✅ Referencias cruzadas establecidas</li>";
    echo "</ul>";
    echo "<p><strong>Próximo paso:</strong> Abre <a href='../crm_clients.php'><b>crm_clients.php</b></a> para ver el nuevo CRM Premium en acción.</p>";

} catch (Throwable $e) {
    echo "<div class='alert alert-danger'>";
    echo "<h4>❌ Error durante la migración:</h4>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<p><small>Trace: " . htmlspecialchars($e->getTraceAsString()) . "</small></p>";
    echo "</div>";
    exit(1);
}
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Upgrade CRM Premium</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light p-5">
    <div class="container">
        <div class="card shadow-lg">
            <div class="card-body p-5">
                <!-- Los mensajes se imprimen arriba, dentro del try/catch -->
            </div>
        </div>
    </div>
</body>
</html>
