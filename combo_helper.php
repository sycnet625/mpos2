<?php

if (!function_exists('combo_ensure_schema')) {
    function combo_ensure_schema(PDO $pdo): void {
        static $ready = false;
        if ($ready) {
            return;
        }

        $cols = [];
        foreach ($pdo->query("SHOW COLUMNS FROM productos") as $col) {
            $cols[(string)($col['Field'] ?? '')] = true;
        }

        if (!isset($cols['es_combo'])) {
            $pdo->exec("ALTER TABLE productos ADD COLUMN es_combo TINYINT(1) NOT NULL DEFAULT 0");
        }

        $tableExists = false;
        try {
            $tableExists = (bool)$pdo->query("SHOW TABLES LIKE 'producto_combo_items'")->fetchColumn();
        } catch (Throwable $e) {
            $tableExists = false;
        }

        if (!$tableExists) {
            $pdo->exec(
                "CREATE TABLE producto_combo_items (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    combo_codigo VARCHAR(50) NOT NULL,
                    componente_codigo VARCHAR(50) NOT NULL,
                    cantidad DECIMAL(12,3) NOT NULL DEFAULT 1.000,
                    orden INT NOT NULL DEFAULT 0,
                    created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
                    UNIQUE KEY uniq_combo_componente (combo_codigo, componente_codigo),
                    KEY idx_combo (combo_codigo),
                    KEY idx_componente (componente_codigo)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
            );
        }

        $ready = true;
    }
}

if (!function_exists('combo_component_catalog')) {
    function combo_component_catalog(PDO $pdo, int $idEmpresa): array {
        combo_ensure_schema($pdo);

        $stmt = $pdo->prepare(
            "SELECT codigo, nombre, categoria, COALESCE(costo, 0) AS costo, es_servicio, es_elaborado
             FROM productos
             WHERE id_empresa = ? AND activo = 1 AND COALESCE(es_combo, 0) = 0
             ORDER BY nombre ASC"
        );
        $stmt->execute([$idEmpresa]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('combo_fetch_definitions')) {
    function combo_fetch_definitions(PDO $pdo, int $idEmpresa, array $comboCodes): array {
        combo_ensure_schema($pdo);

        $comboCodes = array_values(array_unique(array_filter(array_map('strval', $comboCodes))));
        if (!$comboCodes) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($comboCodes), '?'));
        $params = array_merge($comboCodes, [$idEmpresa]);
        $stmt = $pdo->prepare(
            "SELECT ci.combo_codigo, ci.componente_codigo, ci.cantidad, ci.orden,
                    p.nombre AS componente_nombre,
                    COALESCE(p.costo, 0) AS componente_costo,
                    COALESCE(p.es_servicio, 0) AS es_servicio,
                    COALESCE(p.es_elaborado, 0) AS es_elaborado
             FROM producto_combo_items ci
             INNER JOIN productos p ON p.codigo = ci.componente_codigo AND p.id_empresa = ?
             WHERE ci.combo_codigo IN ($ph)
             ORDER BY ci.combo_codigo ASC, ci.orden ASC, ci.id ASC"
        );
        $stmt->execute(array_merge([$idEmpresa], $comboCodes));

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $combo = (string)$row['combo_codigo'];
            if (!isset($out[$combo])) {
                $out[$combo] = [];
            }
            $out[$combo][] = [
                'codigo' => (string)$row['componente_codigo'],
                'nombre' => (string)($row['componente_nombre'] ?? $row['componente_codigo']),
                'cantidad' => max(0.001, floatval($row['cantidad'] ?? 1)),
                'costo' => floatval($row['componente_costo'] ?? 0),
                'es_servicio' => intval($row['es_servicio'] ?? 0),
                'es_elaborado' => intval($row['es_elaborado'] ?? 0),
                'orden' => intval($row['orden'] ?? 0),
            ];
        }

        return $out;
    }
}

if (!function_exists('combo_stock_levels')) {
    function combo_stock_levels(PDO $pdo, int $idAlmacen, array $codes): array {
        $codes = array_values(array_unique(array_filter(array_map('strval', $codes))));
        if (!$codes) {
            return [];
        }

        $ph = implode(',', array_fill(0, count($codes), '?'));
        $params = array_merge([$idAlmacen], $codes);
        $stmt = $pdo->prepare(
            "SELECT id_producto, COALESCE(SUM(cantidad), 0) AS cantidad
             FROM stock_almacen
             WHERE id_almacen = ? AND id_producto IN ($ph)
             GROUP BY id_producto"
        );
        $stmt->execute($params);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(string)$row['id_producto']] = floatval($row['cantidad'] ?? 0);
        }

        return $out;
    }
}

if (!function_exists('combo_virtual_stock')) {
    function combo_virtual_stock(array $components, array $stockMap): float {
        if (!$components) {
            return 0.0;
        }

        $minUnits = null;
        foreach ($components as $component) {
            if (intval($component['es_servicio'] ?? 0) === 1) {
                continue;
            }

            $required = max(0.001, floatval($component['cantidad'] ?? 1));
            $available = floatval($stockMap[$component['codigo']] ?? 0);
            $units = floor(($available / $required) + 1.0e-9);
            $minUnits = $minUnits === null ? $units : min($minUnits, $units);
        }

        return max(0, floatval($minUnits ?? 0));
    }
}

if (!function_exists('combo_apply_product_rows')) {
    function combo_apply_product_rows(PDO $pdo, array $rows, int $idEmpresa, int $idAlmacen): array {
        combo_ensure_schema($pdo);

        if (!$rows) {
            return $rows;
        }

        $comboCodes = [];
        foreach ($rows as $row) {
            if (intval($row['es_combo'] ?? 0) === 1) {
                $comboCodes[] = (string)($row['codigo'] ?? $row['id'] ?? '');
            }
        }

        $defs = combo_fetch_definitions($pdo, $idEmpresa, $comboCodes);
        $componentCodes = [];
        foreach ($defs as $items) {
            foreach ($items as $item) {
                if (intval($item['es_servicio']) !== 1) {
                    $componentCodes[] = $item['codigo'];
                }
            }
        }
        $stockMap = combo_stock_levels($pdo, $idAlmacen, $componentCodes);

        foreach ($rows as &$row) {
            $code = (string)($row['codigo'] ?? $row['id'] ?? '');
            $row['es_combo'] = intval($row['es_combo'] ?? 0);

            if ($row['es_combo'] !== 1) {
                if (isset($row['stock'])) {
                    $row['stock'] = floatval($row['stock']);
                }
                if (isset($row['stock_total'])) {
                    $row['stock_total'] = floatval($row['stock_total']);
                }
                continue;
            }

            $items = $defs[$code] ?? [];
            $comboStock = combo_virtual_stock($items, $stockMap);
            $comboCost = 0.0;
            $summary = [];
            foreach ($items as $item) {
                $comboCost += floatval($item['costo'] ?? 0) * floatval($item['cantidad'] ?? 0);
                $summary[] = rtrim(rtrim(number_format(floatval($item['cantidad']), 3, '.', ''), '0'), '.') . ' x ' . $item['nombre'];
            }

            $row['stock'] = $comboStock;
            if (array_key_exists('stock_total', $row)) {
                $row['stock_total'] = $comboStock;
            }
            if (array_key_exists('stock_actual', $row)) {
                $row['stock_actual'] = $comboStock;
            }
            $row['combo_items'] = $items;
            $row['combo_resumen'] = implode(' + ', $summary);
            $row['combo_costo'] = round($comboCost, 2);
        }
        unset($row);

        return $rows;
    }
}

if (!function_exists('combo_save_definition')) {
    function combo_save_definition(PDO $pdo, int $idEmpresa, string $comboCode, array $items): array {
        combo_ensure_schema($pdo);

        $comboCode = trim($comboCode);
        if ($comboCode === '') {
            throw new Exception('Código de combo inválido.');
        }

        $catalog = [];
        foreach (combo_component_catalog($pdo, $idEmpresa) as $row) {
            $catalog[(string)$row['codigo']] = $row;
        }

        $normalized = [];
        foreach ($items as $index => $item) {
            $code = trim((string)($item['codigo'] ?? ''));
            $qty = floatval($item['cantidad'] ?? 0);
            if ($code === '' || $qty <= 0) {
                continue;
            }
            if ($code === $comboCode) {
                throw new Exception('Un combo no puede incluirse a sí mismo.');
            }
            if (!isset($catalog[$code])) {
                throw new Exception("El componente {$code} no existe o no está activo.");
            }

            if (!isset($normalized[$code])) {
                $normalized[$code] = [
                    'codigo' => $code,
                    'cantidad' => 0.0,
                    'orden' => $index,
                    'nombre' => (string)$catalog[$code]['nombre'],
                    'costo' => floatval($catalog[$code]['costo'] ?? 0),
                    'es_servicio' => intval($catalog[$code]['es_servicio'] ?? 0),
                    'es_elaborado' => intval($catalog[$code]['es_elaborado'] ?? 0),
                ];
            }
            $normalized[$code]['cantidad'] += $qty;
        }

        if (!$normalized) {
            throw new Exception('Debe agregar al menos un componente al combo.');
        }

        $pdo->prepare("DELETE FROM producto_combo_items WHERE combo_codigo = ?")->execute([$comboCode]);
        $stmtIns = $pdo->prepare(
            "INSERT INTO producto_combo_items (combo_codigo, componente_codigo, cantidad, orden)
             VALUES (?, ?, ?, ?)"
        );

        $autoCost = 0.0;
        foreach (array_values($normalized) as $item) {
            $stmtIns->execute([$comboCode, $item['codigo'], $item['cantidad'], $item['orden']]);
            $autoCost += floatval($item['costo']) * floatval($item['cantidad']);
        }

        $pdo->prepare("UPDATE productos SET es_combo = 1, costo = ? WHERE codigo = ? AND id_empresa = ?")
            ->execute([round($autoCost, 2), $comboCode, $idEmpresa]);

        return [
            'items' => array_values($normalized),
            'auto_cost' => round($autoCost, 2),
        ];
    }
}

if (!function_exists('combo_expand_sale_items')) {
    function combo_expand_sale_items(PDO $pdo, int $idEmpresa, array $saleItems): array {
        combo_ensure_schema($pdo);

        $saleCodes = [];
        foreach ($saleItems as $item) {
            $code = trim((string)($item['id'] ?? $item['codigo'] ?? ''));
            if ($code !== '') {
                $saleCodes[] = $code;
            }
        }
        $saleCodes = array_values(array_unique($saleCodes));

        $productMap = [];
        if ($saleCodes) {
            $ph = implode(',', array_fill(0, count($saleCodes), '?'));
            $stmt = $pdo->prepare(
                "SELECT codigo, nombre, COALESCE(es_servicio, 0) AS es_servicio,
                        COALESCE(es_elaborado, 0) AS es_elaborado, COALESCE(es_combo, 0) AS es_combo
                 FROM productos
                 WHERE id_empresa = ? AND codigo IN ($ph)"
            );
            $stmt->execute(array_merge([$idEmpresa], $saleCodes));
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $productMap[(string)$row['codigo']] = $row;
            }
        }

        $comboCodes = [];
        foreach ($saleCodes as $code) {
            if (intval($productMap[$code]['es_combo'] ?? 0) === 1) {
                $comboCodes[] = $code;
            }
        }

        $defs = combo_fetch_definitions($pdo, $idEmpresa, $comboCodes);
        $inventoryLines = [];
        $kitchenItems = [];

        foreach ($saleItems as $item) {
            $saleCode = trim((string)($item['id'] ?? $item['codigo'] ?? ''));
            $saleQty = floatval($item['qty'] ?? 0);
            if ($saleCode === '' || $saleQty <= 0) {
                continue;
            }

            $product = $productMap[$saleCode] ?? [
                'codigo' => $saleCode,
                'nombre' => (string)($item['name'] ?? $saleCode),
                'es_servicio' => 0,
                'es_elaborado' => 0,
                'es_combo' => 0,
            ];

            $note = trim((string)($item['note'] ?? ''));
            if (intval($product['es_combo'] ?? 0) === 1) {
                foreach (($defs[$saleCode] ?? []) as $component) {
                    $requiredQty = $saleQty * floatval($component['cantidad'] ?? 0);
                    if ($requiredQty <= 0) {
                        continue;
                    }

                    if (intval($component['es_servicio'] ?? 0) !== 1) {
                        if (!isset($inventoryLines[$component['codigo']])) {
                            $inventoryLines[$component['codigo']] = [
                                'id' => $component['codigo'],
                                'nombre' => $component['nombre'],
                                'qty' => 0.0,
                            ];
                        }
                        $inventoryLines[$component['codigo']]['qty'] += $requiredQty;
                    }

                    if (intval($component['es_elaborado'] ?? 0) === 1) {
                        $kitchenItems[] = [
                            'qty' => $requiredQty,
                            'name' => $component['nombre'],
                            'note' => trim(($note !== '' ? $note . ' | ' : '') . 'Combo: ' . ($product['nombre'] ?? $saleCode)),
                        ];
                    }
                }
                continue;
            }

            if (intval($product['es_servicio'] ?? 0) !== 1) {
                if (!isset($inventoryLines[$saleCode])) {
                    $inventoryLines[$saleCode] = [
                        'id' => $saleCode,
                        'nombre' => (string)($product['nombre'] ?? $saleCode),
                        'qty' => 0.0,
                    ];
                }
                $inventoryLines[$saleCode]['qty'] += $saleQty;
            }

            if (intval($product['es_elaborado'] ?? 0) === 1) {
                $kitchenItems[] = [
                    'qty' => $saleQty,
                    'name' => (string)($product['nombre'] ?? $saleCode),
                    'note' => $note,
                ];
            }
        }

        return [
            'inventory_items' => array_values($inventoryLines),
            'kitchen_items' => $kitchenItems,
            'product_map' => $productMap,
            'combo_map' => $defs,
        ];
    }
}

if (!function_exists('combo_check_stock')) {
    function combo_check_stock(PDO $pdo, int $idEmpresa, int $idAlmacen, array $saleItems): array {
        $expanded = combo_expand_sale_items($pdo, $idEmpresa, $saleItems);
        $codes = array_column($expanded['inventory_items'], 'id');
        $stockMap = combo_stock_levels($pdo, $idAlmacen, $codes);

        $allOk = true;
        $out = [];
        foreach ($expanded['inventory_items'] as $item) {
            $available = floatval($stockMap[$item['id']] ?? 0);
            $needed = floatval($item['qty'] ?? 0);
            if ($available + 1.0e-9 < $needed) {
                $allOk = false;
                $out[] = [
                    'id' => $item['id'],
                    'nombre' => $item['nombre'],
                    'stock' => $available,
                    'needed' => $needed,
                ];
            }
        }

        return [
            'all_ok' => $allOk,
            'out' => $out,
            'expanded' => $expanded,
        ];
    }
}
