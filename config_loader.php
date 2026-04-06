<?php
// ARCHIVO: config_loader.php
// Carga centralizada de la configuración desde pos.cfg
// Uso: require_once 'config_loader.php'; (provee $config global)

if (!function_exists('config_loader_apply_session_context')) {
    function config_loader_apply_session_context(array &$config): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $sessionSuc = (int)($_SESSION['id_sucursal'] ?? 0);
        $sessionAlm = (int)($_SESSION['id_almacen'] ?? 0);
        $sessionEmp = (int)($_SESSION['id_empresa'] ?? 0);

        if ($sessionSuc > 0) {
            $config['id_sucursal'] = $sessionSuc;
        }
        if ($sessionAlm > 0) {
            $config['id_almacen'] = $sessionAlm;
        }
        if ($sessionEmp > 0) {
            $config['id_empresa'] = $sessionEmp;
        }
    }
}

if (!function_exists('config_loader_apply_user_context')) {
    function config_loader_apply_user_context(array &$config): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (!isset($GLOBALS['pdo']) || !($GLOBALS['pdo'] instanceof PDO)) {
            return;
        }

        $userId = (int)($_SESSION['user_id'] ?? 0);
        if ($userId <= 0) {
            return;
        }

        try {
            $stmt = $GLOBALS['pdo']->prepare("
                SELECT id_empresa, id_sucursal, id_almacen
                FROM pos_user_contexts
                WHERE user_id = ? AND COALESCE(activo, 1) = 1
                LIMIT 1
            ");
            $stmt->execute([$userId]);
            $ctx = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ctx) {
                return;
            }

            $emp = (int)($ctx['id_empresa'] ?? 0);
            $suc = (int)($ctx['id_sucursal'] ?? 0);
            $alm = (int)($ctx['id_almacen'] ?? 0);

            if ($emp > 0) {
                $config['id_empresa'] = $emp;
            }
            if ($suc > 0) {
                $config['id_sucursal'] = $suc;
            }
            if ($alm > 0) {
                $config['id_almacen'] = $alm;
            }
        } catch (Throwable $e) {
            // Mantener fallback a pos.cfg si la tabla no existe o falla el query.
        }
    }
}

if (!isset($config)) {
    $configFile = __DIR__ . '/pos.cfg';
    $config = [
        "tienda_nombre" => "MI TIENDA",
        "direccion" => "",
        "telefono" => "",
        "email" => "",
        "website" => "",
        "nit" => "",
        "cuenta_bancaria" => "",
        "banco" => "",
        "mensaje_final" => "¡Gracias por su compra!",
        "id_empresa" => 1,
        "id_sucursal" => 1,
        "id_almacen" => 1,
        "mostrar_materias_primas" => false,
        "mostrar_servicios" => true,
        "categorias_ocultas" => [],
        "mensajeria_tarifa_km" => 150,
        "cajeros" => [],
        "numero_tarjeta" => "",
        "titular_tarjeta" => "",
        "banco_tarjeta" => "Bandec / BPA",
        // Diseño del ticket
        "ticket_logo" => "",
        "ticket_slogan" => "",
        "ticket_mostrar_uuid" => false,
        "ticket_mostrar_canal" => true,
        "ticket_mostrar_cajero" => true,
        "ticket_mostrar_qr" => true,
        "ticket_mostrar_items_count" => true,
        // Multi-divisa y métodos de pago
        "tipo_cambio_usd"    => 385.00,
        "tipo_cambio_mlc"    => 310.00,
        "moneda_default_pos" => "CUP",
        "metodos_pago" => [
            ["id"=>"Efectivo",      "nombre"=>"Efectivo",      "icono"=>"fa-money-bill-wave","color_bootstrap"=>"success","activo"=>true,"requiere_codigo"=>false,"aplica_pos"=>true, "aplica_shop"=>true, "es_transferencia"=>false,"es_especial"=>false,"texto_especial"=>""],
            ["id"=>"Transferencia", "nombre"=>"Transferencia", "icono"=>"fa-university",     "color_bootstrap"=>"primary","activo"=>true,"requiere_codigo"=>true, "aplica_pos"=>true, "aplica_shop"=>true, "es_transferencia"=>true, "es_especial"=>false,"texto_especial"=>""],
            ["id"=>"Tarjeta",       "nombre"=>"Tarjeta/Gasto", "icono"=>"fa-credit-card",    "color_bootstrap"=>"warning","activo"=>true,"requiere_codigo"=>false,"aplica_pos"=>true, "aplica_shop"=>false,"es_transferencia"=>false,"es_especial"=>false,"texto_especial"=>""],
        ],
    ];

    if (file_exists($configFile)) {
        $loaded = json_decode(file_get_contents($configFile), true);
        if ($loaded) {
            $config = array_merge($config, $loaded);
        }
    }

    // Prioridad:
    // 1) Contexto guardado en sesión (PIN/cajero u otros flujos)
    // 2) Contexto dinámico por usuario ERP logueado
    config_loader_apply_session_context($config);
    config_loader_apply_user_context($config);
}
