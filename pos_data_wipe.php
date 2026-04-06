<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', 0);
require_once 'db.php';
require_once 'config_loader.php';

const POS_WIPE_PIN = '3120';

function wipe_table_columns(PDO $pdo, string $table): array
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    try {
        $stmt = $pdo->query("SHOW COLUMNS FROM `$table`");
        $cache[$table] = $stmt ? array_map('strtolower', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'Field')) : [];
    } catch (Throwable $e) {
        $cache[$table] = [];
    }
    return $cache[$table];
}

function wipe_table_has_column(PDO $pdo, string $table, string $column): bool
{
    return in_array(strtolower($column), wipe_table_columns($pdo, $table), true);
}

function wipe_first_existing_column(PDO $pdo, string $table, array $candidates): ?string
{
    foreach ($candidates as $candidate) {
        if (wipe_table_has_column($pdo, $table, $candidate)) {
            return $candidate;
        }
    }
    return null;
}

function wipe_parent_scope_clause(string $parentAlias, string $scopeMode, ?int $empresaId, ?int $sucursalId): array
{
    if ($scopeMode === 'empresa') {
        return ["{$parentAlias}.id_empresa = ?", [$empresaId]];
    }
    if ($scopeMode === 'sucursal') {
        return ["{$parentAlias}.id_empresa = ? AND {$parentAlias}.id_sucursal = ?", [$empresaId, $sucursalId]];
    }
    return ['1=1', []];
}

function wipe_direct_scope_sql(PDO $pdo, string $table, string $scopeMode, ?int $empresaId, ?int $sucursalId): ?array
{
    if ($scopeMode === 'global') {
        return ["DELETE FROM `$table`", []];
    }

    if (wipe_table_has_column($pdo, $table, 'id_empresa')) {
        if ($scopeMode === 'empresa') {
            return ["DELETE FROM `$table` WHERE id_empresa = ?", [$empresaId]];
        }
        if ($scopeMode === 'sucursal' && wipe_table_has_column($pdo, $table, 'id_sucursal')) {
            return ["DELETE FROM `$table` WHERE id_empresa = ? AND id_sucursal = ?", [$empresaId, $sucursalId]];
        }
        if ($scopeMode === 'sucursal' && wipe_table_has_column($pdo, $table, 'id_almacen')) {
            return [
                "DELETE FROM `$table` WHERE id_empresa = ? AND id_almacen IN (SELECT id FROM almacenes WHERE id_sucursal = ?)",
                [$empresaId, $sucursalId]
            ];
        }
        return ["DELETE FROM `$table` WHERE id_empresa = ?", [$empresaId]];
    }

    if (wipe_table_has_column($pdo, $table, 'id_sucursal')) {
        if ($scopeMode === 'empresa') {
            return ["DELETE FROM `$table` WHERE id_sucursal IN (SELECT id FROM sucursales WHERE id_empresa = ?)", [$empresaId]];
        }
        return ["DELETE FROM `$table` WHERE id_sucursal = ?", [$sucursalId]];
    }

    if (wipe_table_has_column($pdo, $table, 'id_almacen')) {
        if ($scopeMode === 'empresa') {
            return [
                "DELETE FROM `$table` WHERE id_almacen IN (
                    SELECT a.id FROM almacenes a
                    INNER JOIN sucursales s ON s.id = a.id_sucursal
                    WHERE s.id_empresa = ?
                )",
                [$empresaId]
            ];
        }
        return ["DELETE FROM `$table` WHERE id_almacen IN (SELECT id FROM almacenes WHERE id_sucursal = ?)", [$sucursalId]];
    }

    if ($table === 'transferencias_cabecera') {
        if ($scopeMode === 'empresa') {
            return [
                "DELETE FROM `$table` WHERE
                    (id_sucursal_origen IN (SELECT id FROM sucursales WHERE id_empresa = ?))
                    OR
                    (id_sucursal_destino IN (SELECT id FROM sucursales WHERE id_empresa = ?))",
                [$empresaId, $empresaId]
            ];
        }
        return [
            "DELETE FROM `$table` WHERE id_sucursal_origen = ? OR id_sucursal_destino = ?",
            [$sucursalId, $sucursalId]
        ];
    }

    if ($table === 'transferencias_detalle') {
        $parentScope = wipe_parent_scope_clause('tc', $scopeMode, $empresaId, $sucursalId);
        return [
            "DELETE td FROM `$table` td
             INNER JOIN transferencias_cabecera tc ON tc.id = td.id_transferencia
             WHERE {$parentScope[0]}",
            $parentScope[1]
        ];
    }

    return null;
}

function wipe_table_sql(PDO $pdo, string $table, string $scopeMode, ?int $empresaId, ?int $sucursalId, bool $reservationOnly = false): ?array
{
    if ($reservationOnly) {
        if ($table === 'ventas_cabecera') {
            $parentScope = wipe_parent_scope_clause('vc', $scopeMode, $empresaId, $sucursalId);
            return [
                "DELETE vc FROM ventas_cabecera vc WHERE vc.tipo_servicio = 'reserva' AND {$parentScope[0]}",
                $parentScope[1]
            ];
        }
        if ($table === 'ventas_detalle') {
            $fk = wipe_first_existing_column($pdo, 'ventas_detalle', ['id_venta_cabecera', 'id_venta']);
            if ($fk === null) {
                return null;
            }
            $parentScope = wipe_parent_scope_clause('vc', $scopeMode, $empresaId, $sucursalId);
            return [
                "DELETE vd FROM ventas_detalle vd
                 INNER JOIN ventas_cabecera vc ON vc.id = vd.`$fk`
                 WHERE vc.tipo_servicio = 'reserva' AND {$parentScope[0]}",
                $parentScope[1]
            ];
        }
        if ($table === 'ventas_pagos') {
            $fk = wipe_first_existing_column($pdo, 'ventas_pagos', ['id_venta_cabecera', 'id_venta']);
            if ($fk === null) {
                return null;
            }
            $parentScope = wipe_parent_scope_clause('vc', $scopeMode, $empresaId, $sucursalId);
            return [
                "DELETE vp FROM ventas_pagos vp
                 INNER JOIN ventas_cabecera vc ON vc.id = vp.`$fk`
                 WHERE vc.tipo_servicio = 'reserva' AND {$parentScope[0]}",
                $parentScope[1]
            ];
        }
        if ($table === 'comandas') {
            if (!wipe_table_has_column($pdo, 'comandas', 'id_venta')) {
                return null;
            }
            $parentScope = wipe_parent_scope_clause('vc', $scopeMode, $empresaId, $sucursalId);
            return [
                "DELETE c FROM comandas c
                 INNER JOIN ventas_cabecera vc ON vc.id = c.id_venta
                 WHERE vc.tipo_servicio = 'reserva' AND {$parentScope[0]}",
                $parentScope[1]
            ];
        }
        return null;
    }

    $detailMap = [
        'ventas_detalle' => ['parent' => 'ventas_cabecera', 'fk' => ['id_venta_cabecera', 'id_venta']],
        'ventas_pagos' => ['parent' => 'ventas_cabecera', 'fk' => ['id_venta_cabecera', 'id_venta']],
        'comandas' => ['parent' => 'ventas_cabecera', 'fk' => ['id_venta']],
        'recetas_detalle' => ['parent' => 'recetas_cabecera', 'fk' => ['id_receta']],
        'compras_detalle' => ['parent' => 'compras_cabecera', 'fk' => ['id_compra']],
        'transferencias_detalle' => ['parent' => 'transferencias_cabecera', 'fk' => ['id_transferencia']],
        'pedidos_detalle' => ['parent' => 'pedidos_cabecera', 'fk' => ['id_pedido']],
        'mermas_detalle' => ['parent' => 'mermas_cabecera', 'fk' => ['id_merma']],
        'producto_variantes' => ['parent' => 'productos', 'fk' => ['id_producto', 'producto_id']],
        'restock_avisos' => ['parent' => 'productos', 'fk' => ['id_producto', 'producto_id', 'codigo_producto']],
        'resenas_productos' => ['parent' => 'productos', 'fk' => ['id_producto', 'producto_id', 'codigo_producto']],
        'vistas_productos' => ['parent' => 'productos', 'fk' => ['id_producto', 'producto_id', 'codigo_producto']],
        'wishlist' => ['parent' => 'productos', 'fk' => ['id_producto', 'producto_id', 'codigo_producto']],
    ];

    if (isset($detailMap[$table])) {
        $parent = $detailMap[$table]['parent'];
        $fk = wipe_first_existing_column($pdo, $table, $detailMap[$table]['fk']);
        if ($fk === null) {
            return null;
        }

        if ($parent === 'productos' && $fk === 'codigo_producto') {
            $parentScope = wipe_parent_scope_clause('p', $scopeMode, $empresaId, $sucursalId);
            return [
                "DELETE t FROM `$table` t
                 INNER JOIN productos p ON p.codigo = t.codigo_producto
                 WHERE {$parentScope[0]}",
                $parentScope[1]
            ];
        }

        $parentScope = wipe_parent_scope_clause('p', $scopeMode, $empresaId, $sucursalId);
        return [
            "DELETE t FROM `$table` t
             INNER JOIN `$parent` p ON p.id = t.`$fk`
             WHERE {$parentScope[0]}",
            $parentScope[1]
        ];
    }

    return wipe_direct_scope_sql($pdo, $table, $scopeMode, $empresaId, $sucursalId);
}

$sections = [
    'ventas' => [
        'label' => 'Ventas',
        'icon' => 'fa-cash-register',
        'description' => 'Vacía ventas, detalle y pagos registrados.',
        'tables' => ['ventas_pagos', 'ventas_detalle', 'ventas_cabecera'],
    ],
    'productos' => [
        'label' => 'Productos',
        'icon' => 'fa-box-open',
        'description' => 'Vacía productos, variantes, stock y señales relacionadas.',
        'tables' => ['restock_avisos', 'producto_variantes', 'stock_almacen', 'resenas_productos', 'vistas_productos', 'wishlist', 'productos'],
    ],
    'recetas' => [
        'label' => 'Recetas',
        'icon' => 'fa-utensils',
        'description' => 'Vacía recetas y su detalle.',
        'tables' => ['recetas_detalle', 'recetas_cabecera'],
    ],
    'compras' => [
        'label' => 'Compras',
        'icon' => 'fa-truck-loading',
        'description' => 'Vacía compras de prueba.',
        'tables' => ['compras_detalle', 'compras_cabecera'],
    ],
    'transferencias' => [
        'label' => 'Transferencias',
        'icon' => 'fa-exchange-alt',
        'description' => 'Vacía transferencias entre sucursales/almacenes.',
        'tables' => ['transferencias_detalle', 'transferencias_cabecera'],
    ],
    'reservas' => [
        'label' => 'Reservas',
        'icon' => 'fa-calendar-check',
        'description' => 'Elimina solo las ventas de tipo reserva, sus detalles, pagos y comandas relacionadas.',
        'tables' => ['comandas', 'ventas_pagos', 'ventas_detalle', 'ventas_cabecera'],
        'reservation_only' => true,
    ],
    'pedidos' => [
        'label' => 'Pedidos y comandas',
        'icon' => 'fa-receipt',
        'description' => 'Vacía pedidos, espera, comandas y paralelas.',
        'tables' => ['comandas', 'ordenes_paralelas', 'pedidos_detalle', 'pedidos_cabecera', 'pedidos_espera'],
    ],
    'clientes' => [
        'label' => 'Clientes',
        'icon' => 'fa-users',
        'description' => 'Vacía clientes y clientes tienda.',
        'tables' => ['chat_messages', 'carritos_abandonados', 'clientes_tienda', 'clientes'],
    ],
    'produccion' => [
        'label' => 'Producción y mermas',
        'icon' => 'fa-industry',
        'description' => 'Vacía historial de producción y mermas.',
        'tables' => ['mermas_detalle', 'mermas_cabecera', 'producciones_historial'],
    ],
    'bot_pos' => [
        'label' => 'POS Bot',
        'icon' => 'fa-robot',
        'description' => 'Vacía sesiones, mensajes y órdenes del bot.',
        'tables' => ['pos_bot_messages', 'pos_bot_orders', 'pos_bot_sessions'],
    ],
    'carrito_web' => [
        'label' => 'Carritos / wishlist web',
        'icon' => 'fa-shopping-cart',
        'description' => 'Vacía wishlist y carritos abandonados.',
        'tables' => ['wishlist', 'carritos_abandonados'],
    ],
];

$msg = '';
$msgType = 'success';
$executed = [];
$skipped = [];

try {
    $allTables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $allTables = [];
}

try {
    $empresas = $pdo->query("SELECT id, nombre FROM empresas ORDER BY nombre")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $empresas = [];
}

try {
    $sucursales = $pdo->query("
        SELECT s.id, s.nombre, s.id_empresa, e.nombre AS empresa_nombre
        FROM sucursales s
        LEFT JOIN empresas e ON e.id = s.id_empresa
        ORDER BY e.nombre, s.nombre
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $sucursales = [];
}

$safeTables = [];
foreach ($sections as $section) {
    foreach ($section['tables'] as $table) {
        $safeTables[$table] = true;
    }
}
$safeTables = array_keys($safeTables);
sort($safeTables);
$otherTables = array_values(array_diff($allTables, $safeTables));
sort($otherTables);

$counts = [];
foreach ($safeTables as $table) {
    try {
        $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
    } catch (Throwable $e) {
        $counts[$table] = null;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['form_action'] ?? '') === 'purge_sections') {
    try {
        $pin = trim((string)($_POST['security_pin'] ?? ''));
        $selected = array_values(array_unique(array_filter($_POST['sections'] ?? [])));
        $scopeMode = (string)($_POST['scope_mode'] ?? 'global');
        $scopeEmpresaId = (int)($_POST['scope_empresa_id'] ?? 0);
        $scopeSucursalId = (int)($_POST['scope_sucursal_id'] ?? 0);
        if ($pin !== POS_WIPE_PIN) {
            throw new RuntimeException('PIN incorrecto.');
        }
        if (empty($selected)) {
            throw new RuntimeException('Selecciona al menos una sección para vaciar.');
        }
        if (!in_array($scopeMode, ['global', 'empresa', 'sucursal'], true)) {
            $scopeMode = 'global';
        }
        if ($scopeMode !== 'global' && $scopeEmpresaId <= 0) {
            throw new RuntimeException('Selecciona una empresa válida para el borrado filtrado.');
        }
        if ($scopeMode === 'sucursal' && $scopeSucursalId <= 0) {
            throw new RuntimeException('Selecciona una sucursal válida para el borrado filtrado.');
        }

        $tablesToClear = [];
        $reservationTables = [];
        foreach ($selected as $sectionKey) {
            if (!isset($sections[$sectionKey])) {
                continue;
            }
            foreach ($sections[$sectionKey]['tables'] as $table) {
                if (!empty($sections[$sectionKey]['reservation_only'])) {
                    $reservationTables[$table] = true;
                } else {
                    $tablesToClear[$table] = true;
                }
            }
        }
        $tablesToClear = array_keys($tablesToClear);
        $reservationTables = array_keys($reservationTables);

        $usedTransaction = false;
        try {
            if ($pdo->beginTransaction()) {
                $usedTransaction = true;
            }
        } catch (Throwable $ignored) {
            $usedTransaction = false;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
        try {
            $deleteQueue = [];
            foreach ($tablesToClear as $table) {
                $deleteQueue[] = ['table' => $table, 'reservation_only' => false];
            }
            foreach ($reservationTables as $table) {
                $deleteQueue[] = ['table' => $table, 'reservation_only' => true];
            }

            foreach ($deleteQueue as $entry) {
                $table = $entry['table'];
                if (!in_array($table, $safeTables, true)) {
                    continue;
                }

                $deleteSpec = wipe_table_sql($pdo, $table, $scopeMode, $scopeEmpresaId, $scopeSucursalId, (bool)$entry['reservation_only']);
                if ($deleteSpec === null) {
                    $skipped[] = $table;
                    continue;
                }

                [$sql, $params] = $deleteSpec;
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);

                if ($scopeMode === 'global' && !$entry['reservation_only']) {
                    try {
                        $pdo->exec("ALTER TABLE `$table` AUTO_INCREMENT = 1");
                    } catch (Throwable $ignored) {
                    }
                }

                $executed[] = $table;
                try {
                    $counts[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `$table`")->fetchColumn();
                } catch (Throwable $e) {
                    $counts[$table] = null;
                }
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            if ($usedTransaction && $pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (Throwable $inner) {
            try {
                $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
            } catch (Throwable $ignored) {
            }
            if ($usedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $inner;
        }

        $scopeText = 'globalmente';
        if ($scopeMode === 'empresa') {
            $scopeText = 'solo para la empresa seleccionada';
        } elseif ($scopeMode === 'sucursal') {
            $scopeText = 'solo para la sucursal seleccionada';
        }
        $msg = 'Operación completada ' . $scopeText . '.';
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            try { $pdo->exec('SET FOREIGN_KEY_CHECKS=1'); } catch (Throwable $ignored) {}
            $pdo->rollBack();
        }
        $msg = $e->getMessage();
        $msgType = 'danger';
    }
}
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Vaciar módulos / tablas</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background: linear-gradient(180deg, #edf2f7 0%, #f7fafc 100%); }
        .wipe-shell { max-width: 1380px; }
        .hero { background: linear-gradient(135deg, #7f1d1d, #b91c1c 55%, #ea580c); color: #fff; border-radius: 24px; padding: 24px 28px; box-shadow: 0 20px 50px rgba(127,29,29,.24); }
        .hero-kpi { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.16); border-radius: 16px; padding: 12px 14px; min-width: 160px; }
        .card { border: 1px solid #e2e8f0; border-radius: 20px; box-shadow: 0 10px 24px rgba(15,23,42,.06); }
        .section-card { border: 1px solid #e2e8f0; border-radius: 18px; background: #fff; box-shadow: inset 0 0 0 1px #f1f5f9; height: 100%; }
        .section-card.selected { border-color: #ef4444; box-shadow: 0 0 0 3px rgba(239,68,68,.14); }
        .table-chip { display:inline-block; padding:4px 10px; border-radius:999px; background:#f8fafc; border:1px solid #e2e8f0; font-size:.8rem; margin:0 6px 6px 0; }
        .db-tree { max-height: 620px; overflow:auto; }
        .db-tree li { margin-bottom: .45rem; }
        .danger-box { border:1px solid #fecaca; background:#fff1f2; color:#9f1239; border-radius:16px; padding:14px 16px; }
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; }
    </style>
</head>
<body class="p-4">
<div class="container-fluid wipe-shell">
    <div class="hero mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4">
            <div>
                <div class="small text-uppercase fw-bold opacity-75 mb-2">Utilidad de mantenimiento</div>
                <h2 class="mb-2"><i class="fas fa-radiation-alt me-2"></i>Vaciar módulos / tablas</h2>
                <div class="opacity-75">Permite dejar en blanco secciones del software de forma controlada. Protegido con PIN.</div>
            </div>
            <div class="d-flex flex-wrap gap-3 align-self-start">
                <div class="hero-kpi"><div class="small opacity-75">Tablas en secciones</div><div class="fs-4 fw-bold"><?= count($safeTables) ?></div></div>
                <div class="hero-kpi"><div class="small opacity-75">Total tablas BD</div><div class="fs-4 fw-bold"><?= count($allTables) ?></div></div>
                <div class="hero-kpi"><div class="small opacity-75">PIN requerido</div><div class="fs-4 fw-bold mono">3xxx</div></div>
                <a href="dashboard.php" class="btn btn-light"><i class="fas fa-arrow-left me-2"></i>Volver</a>
            </div>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?= $msgType ?> shadow-sm"><?= htmlspecialchars($msg, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <?php if (!empty($executed)): ?>
        <div class="alert alert-warning border-0 shadow-sm">
            <div class="fw-bold mb-2">Tablas vaciadas en esta operación</div>
            <?php foreach ($executed as $table): ?><span class="table-chip mono"><?= htmlspecialchars($table) ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($skipped)): ?>
        <div class="alert alert-info border-0 shadow-sm">
            <div class="fw-bold mb-2">Tablas omitidas en modo filtrado</div>
            <div class="small text-muted mb-2">Estas tablas no se vaciaron porque en esta base no se pudo determinar una relación segura para aplicar el filtro por empresa/sucursal.</div>
            <?php foreach (array_values(array_unique($skipped)) as $table): ?><span class="table-chip mono"><?= htmlspecialchars($table) ?></span><?php endforeach; ?>
        </div>
    <?php endif; ?>

    <div class="row g-4">
        <div class="col-xl-8">
            <div class="card mb-4">
                <div class="card-body p-4">
                    <div class="danger-box mb-4">
                        <div class="fw-bold mb-1"><i class="fas fa-exclamation-triangle me-1"></i>Uso delicado</div>
                        <div>Solo vacía datos operativos. No toca estructura, usuarios, configuración, empresas, sucursales, almacenes ni tablas sensibles del sistema. En modo filtrado se omiten las tablas que no puedan relacionarse de forma segura con la empresa o sucursal elegida.</div>
                    </div>
                    <form method="post">
                        <input type="hidden" name="form_action" value="purge_sections">
                        <div class="row g-3 mb-4">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Alcance del borrado</label>
                                <select name="scope_mode" id="scope_mode" class="form-select">
                                    <option value="global">Global</option>
                                    <option value="empresa">Solo una empresa</option>
                                    <option value="sucursal">Solo una sucursal</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Empresa</label>
                                <select name="scope_empresa_id" id="scope_empresa_id" class="form-select">
                                    <option value="0">Selecciona empresa</option>
                                    <?php foreach ($empresas as $empresa): ?>
                                        <option value="<?= (int)$empresa['id'] ?>"><?= htmlspecialchars($empresa['nombre']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="form-label fw-bold">Sucursal</label>
                                <select name="scope_sucursal_id" id="scope_sucursal_id" class="form-select">
                                    <option value="0">Selecciona sucursal</option>
                                    <?php foreach ($sucursales as $sucursal): ?>
                                        <option value="<?= (int)$sucursal['id'] ?>" data-empresa="<?= (int)$sucursal['id_empresa'] ?>">
                                            <?= htmlspecialchars(($sucursal['empresa_nombre'] ?: 'Empresa') . ' · ' . $sucursal['nombre']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row g-3 mb-4">
                            <?php foreach ($sections as $key => $section):
                                $totalRows = 0;
                                foreach ($section['tables'] as $table) {
                                    $totalRows += (int)($counts[$table] ?? 0);
                                }
                            ?>
                                <div class="col-md-6 col-xl-4">
                                    <label class="section-card p-3 d-block">
                                        <div class="form-check mb-2">
                                            <input class="form-check-input section-check" type="checkbox" name="sections[]" value="<?= htmlspecialchars($key) ?>">
                                            <span class="ms-2 fw-bold"><i class="fas <?= htmlspecialchars($section['icon']) ?> me-1 text-danger"></i><?= htmlspecialchars($section['label']) ?></span>
                                        </div>
                                        <div class="small text-muted mb-2"><?= htmlspecialchars($section['description']) ?></div>
                                        <div class="small fw-semibold mb-2">Registros detectados: <?= (int)$totalRows ?></div>
                                        <div>
                                            <?php foreach ($section['tables'] as $table): ?>
                                                <span class="table-chip mono"><?= htmlspecialchars($table) ?><?php if (isset($counts[$table])): ?> · <?= (int)$counts[$table] ?><?php endif; ?></span>
                                            <?php endforeach; ?>
                                        </div>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        </div>

                        <div class="row g-3 align-items-end">
                            <div class="col-md-4">
                                <label class="form-label fw-bold">PIN de seguridad</label>
                                <input type="password" name="security_pin" class="form-control mono" autocomplete="current-password" placeholder="3xxx" required>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label fw-bold">Confirmación</label>
                                <div class="form-control bg-light">Selecciona una o más secciones y luego escribe el PIN para proceder.</div>
                            </div>
                            <div class="col-md-3 d-grid">
                                <button type="submit" class="btn btn-danger btn-lg" onclick="return confirm('Esto vaciará las secciones seleccionadas. ¿Continuar?');">
                                    <i class="fas fa-trash-alt me-2"></i>Vaciar secciones
                                </button>
                            </div>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-xl-4">
            <div class="card mb-4">
                <div class="card-header fw-bold bg-white">📚 Tablas de la base de datos</div>
                <div class="card-body db-tree">
                    <div class="mb-3">
                        <div class="small text-uppercase fw-bold text-muted mb-2">Tablas incluidas en esta utilidad</div>
                        <?php foreach ($safeTables as $table): ?>
                            <span class="table-chip mono"><?= htmlspecialchars($table) ?></span>
                        <?php endforeach; ?>
                    </div>
                    <div>
                        <div class="small text-uppercase fw-bold text-muted mb-2">Resto de tablas solo informativas</div>
                        <?php foreach ($otherTables as $table): ?>
                            <span class="table-chip mono"><?= htmlspecialchars($table) ?></span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.section-check').forEach((checkbox) => {
    const card = checkbox.closest('.section-card');
    const sync = () => card?.classList.toggle('selected', checkbox.checked);
    checkbox.addEventListener('change', sync);
    sync();
});

const scopeMode = document.getElementById('scope_mode');
const empresaSelect = document.getElementById('scope_empresa_id');
const sucursalSelect = document.getElementById('scope_sucursal_id');

function syncScopeVisibility() {
    const mode = scopeMode?.value || 'global';
    empresaSelect.disabled = mode === 'global';
    sucursalSelect.disabled = mode !== 'sucursal';
}

function syncSucursalOptions() {
    const empresaId = empresaSelect?.value || '0';
    const currentValue = sucursalSelect?.value || '0';
    let found = false;
    Array.from(sucursalSelect.options).forEach((option, index) => {
        if (index === 0) {
            option.hidden = false;
            return;
        }
        const match = option.dataset.empresa === empresaId;
        option.hidden = empresaId !== '0' && !match;
        if (match && option.value === currentValue) {
            found = true;
        }
    });
    if (!found) {
        sucursalSelect.value = '0';
    }
}

scopeMode?.addEventListener('change', syncScopeVisibility);
empresaSelect?.addEventListener('change', syncSucursalOptions);
syncScopeVisibility();
syncSucursalOptions();
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
