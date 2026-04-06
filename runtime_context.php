<?php

declare(strict_types=1);

/**
 * Bootstrap mínimo para el contexto operativo POS.
 * Crea tablas/columnas necesarias sin romper instalaciones antiguas.
 */
function pw_runtime_context_bootstrap(PDO $pdo, array &$config): void
{
    foreach ([
        static function () use ($pdo): void { pw_runtime_ensure_location_flags($pdo); },
        static function () use ($pdo): void { pw_runtime_ensure_pos_context_tables($pdo); },
        static function () use ($pdo, &$config): void { pw_runtime_ensure_default_location_ids($pdo, $config); },
    ] as $step) {
        try {
            $step();
        } catch (Throwable $e) {
            // No bloquear pantallas administrativas por fallos de migración en caliente.
        }
    }
}

function pw_runtime_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?"
    );
    $stmt->execute([$table]);
    return ((int)$stmt->fetchColumn() > 0);
}

function pw_runtime_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare(
        "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?"
    );
    $stmt->execute([$table, $column]);
    return ((int)$stmt->fetchColumn() > 0);
}

function pw_runtime_ensure_location_flags(PDO $pdo): void
{
    if (!pw_runtime_column_exists($pdo, 'empresas', 'activo')) {
        $pdo->exec("ALTER TABLE empresas ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1");
    }

    if (!pw_runtime_column_exists($pdo, 'sucursales', 'activo')) {
        $pdo->exec("ALTER TABLE sucursales ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1");
    }

    if (!pw_runtime_column_exists($pdo, 'almacenes', 'activo')) {
        $pdo->exec("ALTER TABLE almacenes ADD COLUMN activo TINYINT(1) NOT NULL DEFAULT 1");
    }
}

function pw_runtime_ensure_pos_context_tables(PDO $pdo): void
{
    if (!pw_runtime_table_exists($pdo, 'pos_user_contexts')) {
        $pdo->exec(
            "CREATE TABLE pos_user_contexts (
                user_id BIGINT(20) UNSIGNED NOT NULL,
                id_empresa INT(11) NOT NULL,
                id_sucursal INT(11) NOT NULL,
                id_almacen INT(11) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (user_id),
                KEY idx_empresa (id_empresa),
                KEY idx_sucursal (id_sucursal),
                KEY idx_almacen (id_almacen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }

    if (!pw_runtime_table_exists($pdo, 'pos_cashiers')) {
        $pdo->exec(
            "CREATE TABLE pos_cashiers (
                id INT(11) NOT NULL AUTO_INCREMENT,
                nombre VARCHAR(100) NOT NULL,
                pin VARCHAR(10) NOT NULL,
                rol VARCHAR(20) NOT NULL DEFAULT 'cajero',
                id_empresa INT(11) NOT NULL,
                id_sucursal INT(11) NOT NULL,
                id_almacen INT(11) NOT NULL,
                activo TINYINT(1) NOT NULL DEFAULT 1,
                created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (id),
                UNIQUE KEY uniq_pin (pin),
                KEY idx_empresa (id_empresa),
                KEY idx_sucursal (id_sucursal),
                KEY idx_almacen (id_almacen)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
        );
    }
}

function pw_runtime_ensure_default_location_ids(PDO $pdo, array &$config): void
{
    $empresaId = (int)($config['id_empresa'] ?? 0);
    $sucursalId = (int)($config['id_sucursal'] ?? 0);
    $almacenId = (int)($config['id_almacen'] ?? 0);

    if ($empresaId <= 0) {
        $empresaId = (int)($pdo->query("SELECT id FROM empresas ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
        if ($empresaId > 0) {
            $config['id_empresa'] = $empresaId;
        }
    }

    if ($sucursalId <= 0) {
        if ($empresaId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM sucursales WHERE id_empresa = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$empresaId]);
            $sucursalId = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($sucursalId <= 0) {
            $sucursalId = (int)($pdo->query("SELECT id FROM sucursales ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
        }
        if ($sucursalId > 0) {
            $config['id_sucursal'] = $sucursalId;
        }
    }

    if ($almacenId <= 0) {
        if ($sucursalId > 0) {
            $stmt = $pdo->prepare("SELECT id FROM almacenes WHERE id_sucursal = ? ORDER BY id ASC LIMIT 1");
            $stmt->execute([$sucursalId]);
            $almacenId = (int)($stmt->fetchColumn() ?: 0);
        }
        if ($almacenId <= 0) {
            $almacenId = (int)($pdo->query("SELECT id FROM almacenes ORDER BY id ASC LIMIT 1")->fetchColumn() ?: 0);
        }
        if ($almacenId > 0) {
            $config['id_almacen'] = $almacenId;
        }
    }
}
