CREATE TABLE IF NOT EXISTS pos_user_contexts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    id_empresa INT NOT NULL,
    id_sucursal INT NOT NULL,
    id_almacen INT NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pos_user_context_user (user_id),
    KEY idx_pos_user_context_sucursal (id_sucursal),
    KEY idx_pos_user_context_almacen (id_almacen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pos_cashiers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    nombre VARCHAR(100) NOT NULL,
    pin VARCHAR(20) NOT NULL,
    rol VARCHAR(20) NOT NULL DEFAULT 'cajero',
    id_empresa INT NOT NULL,
    id_sucursal INT NOT NULL,
    id_almacen INT NOT NULL,
    activo TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_pos_cashier_pin (pin),
    KEY idx_pos_cashier_sucursal (id_sucursal),
    KEY idx_pos_cashier_almacen (id_almacen)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO pos_user_contexts (user_id, id_empresa, id_sucursal, id_almacen, activo)
SELECT
    u.id,
    COALESCE(s.id_empresa, 1) AS id_empresa,
    COALESCE(u.id_sucursal, 1) AS id_sucursal,
    COALESCE(
        (
            SELECT a.id
            FROM almacenes a
            WHERE a.id_sucursal = u.id_sucursal
              AND COALESCE(a.activo, 1) = 1
            ORDER BY a.id ASC
            LIMIT 1
        ),
        1
    ) AS id_almacen,
    COALESCE(u.activo, 1) AS activo
FROM users u
LEFT JOIN sucursales s ON s.id = u.id_sucursal;

INSERT IGNORE INTO pos_cashiers (nombre, pin, rol, id_empresa, id_sucursal, id_almacen, activo)
VALUES ('Admin', '0000', 'admin', 1, 1, 1, 1);
