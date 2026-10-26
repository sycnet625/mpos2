<?php
declare(strict_types=1);

function cakes_ensure_orders_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS cakes_web_orders (
        id_venta INT NOT NULL PRIMARY KEY,
        id_empresa INT NOT NULL,
        token_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        payload_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
        opciones LONGTEXT NOT NULL,
        creado DATETIME NOT NULL,
        UNIQUE KEY uq_cakes_token (token_hash),
        KEY idx_cakes_empresa (id_empresa, creado)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function cakes_token_hash(string $token): string
{
    return hash('sha256', $token);
}
