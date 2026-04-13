<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

ini_set('display_errors', 0);
require_once 'db.php';
require_once 'runtime_context.php';
require_once 'inventory_suite_layout.php';

function poscfg_table_has_column(PDO $pdo, string $table, string $column): bool
{
    static $cache = [];
    $key = $table . '.' . $column;
    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ?
          AND COLUMN_NAME = ?
    ");
    $stmt->execute([$table, $column]);
    $cache[$key] = ((int)$stmt->fetchColumn() > 0);
    return $cache[$key];
}

$configFile = __DIR__ . '/pos.cfg';
$defaultConfig = [
    "marca_sistema_nombre" => "PalWeb POS Marinero",
    "marca_sistema_logo" => "",
    "marca_pos_login_logo" => "",
    "marca_empresa_nombre" => "MI TIENDA",
    "marca_empresa_logo" => "",
    "tienda_nombre" => "MI TIENDA",
    "direccion" => "Dirección",
    "telefono" => "",
    "mensaje_final" => "Gracias",
    "id_empresa" => 1,
    "id_sucursal" => 1,
    "id_almacen" => 1,
    "email" => "",
    "website" => "",
    "nit" => "",
    "cuenta_bancaria" => "",
    "banco" => "",
    "mensajeria_tarifa_km" => 150,
    "mostrar_materias_primas" => false,
    "mostrar_servicios" => true,
    "categorias_ocultas" => [],
    "semana_inicio_dia" => 1,
    "reserva_limpieza_pct" => 0,
    "salario_elaborador_pct" => 0,
    "reserva_negocio_pct" => 0,
    "depreciacion_equipos_pct" => 0,
    "kiosco_solo_stock" => false,
    "customer_display_chime_type" => "mixkit_bell",
    "customer_display_insect" => "mosca",
    "numero_tarjeta" => "",
    "titular_tarjeta" => "",
    "banco_tarjeta" => "Bandec / BPA",
    "facebook_url" => "",
    "twitter_url" => "",
    "instagram_url" => "",
    "youtube_url" => "",
    "ticket_logo" => "",
    "ticket_slogan" => "",
    "ticket_mostrar_uuid" => false,
    "ticket_mostrar_canal" => true,
    "ticket_mostrar_cajero" => true,
    "ticket_mostrar_qr" => true,
    "ticket_mostrar_items_count" => true,
    "tipo_cambio_usd" => 385,
    "tipo_cambio_mlc" => 310,
    "moneda_default_pos" => "CUP",
    "hero_color_1" => "#0f766e",
    "hero_color_2" => "#15803d",
    "hero_mostrar_usuario" => true,
    "hero_mostrar_logo" => true,
    "vapid_public_key" => "",
    "vapid_private_key" => "",
    "metodos_pago" => [
        ["id"=>"Efectivo","nombre"=>"Efectivo","icono"=>"fa-money-bill-wave","color_bootstrap"=>"success","activo"=>true,"requiere_codigo"=>false,"aplica_pos"=>true,"aplica_shop"=>true,"es_transferencia"=>false,"es_especial"=>false,"texto_especial"=>""],
        ["id"=>"Transferencia","nombre"=>"Transferencia","icono"=>"fa-university","color_bootstrap"=>"primary","activo"=>true,"requiere_codigo"=>true,"aplica_pos"=>true,"aplica_shop"=>true,"es_transferencia"=>true,"es_especial"=>false,"texto_especial"=>""],
        ["id"=>"Tarjeta","nombre"=>"Tarjeta/Gasto","icono"=>"fa-credit-card","color_bootstrap"=>"warning","activo"=>true,"requiere_codigo"=>false,"aplica_pos"=>true,"aplica_shop"=>false,"es_transferencia"=>false,"es_especial"=>false,"texto_especial"=>""]
    ],
    "notification_type_settings" => [
        "purchase_new" => true,
        "reservation_new" => true,
        "reservation_manual_created" => true,
        "reservation_web_new" => true,
        "reservation_no_stock" => true,
        "payment_transfer_pending" => true,
        "self_order_new" => true,
        "shop_client_new" => true,
        "bot_new_client" => true,
        "bot_campaign_created" => true,
        "cash_session_opened" => true,
        "cash_session_closed" => true,
        "web_order_new" => true,
        "kitchen_new_ticket" => true
    ],
    "banners" => [
        ["titulo" => "🚀 Envíos a domicilio", "subtitulo" => "Calcula el costo a tu barrio", "imagen" => "", "color_clase" => "gradient-1", "bg_size" => "cover"],
        ["titulo" => "🍰 Dulces Frescos",       "subtitulo" => "Hechos cada mañana",            "imagen" => "", "color_clase" => "gradient-2", "bg_size" => "cover"],
        ["titulo" => "🛒 Ofertas Semanales",    "subtitulo" => "Grandes descuentos",             "imagen" => "", "color_clase" => "gradient-3", "bg_size" => "cover"],
        ["titulo" => "🍕 Comida Caliente",      "subtitulo" => "Lista para llevar",              "imagen" => "", "color_clase" => "gradient-4", "bg_size" => "cover"]
    ],
    "cajeros" => [["nombre" => "Admin", "pin" => "0000", "rol" => "admin"]]
];

$notificationTypeLabels = [
    "purchase_new" => "Compra nueva",
    "reservation_new" => "Nueva reserva",
    "reservation_manual_created" => "Nueva reserva manual",
    "reservation_web_new" => "Nueva reserva web",
    "reservation_no_stock" => "Reserva sin stock",
    "payment_transfer_pending" => "Transferencia pendiente",
    "self_order_new" => "Nuevo autopedido",
    "shop_client_new" => "Nuevo cliente web",
    "bot_new_client" => "Nuevo cliente por auto bot",
    "bot_campaign_created" => "Campaña programada",
    "cash_session_opened" => "Apertura de caja",
    "cash_session_closed" => "Cierre de caja",
    "web_order_new" => "Nuevo pedido web",
    "kitchen_new_ticket" => "Nueva comanda cocina"
];

$currentConfig = $defaultConfig;
if (file_exists($configFile)) {
    $loaded = json_decode(file_get_contents($configFile), true);
    if ($loaded) {
        $currentConfig = array_merge($defaultConfig, $loaded);
    }
}

pw_runtime_context_bootstrap($pdo, $currentConfig);
$hasEmpresaActivo = poscfg_table_has_column($pdo, 'empresas', 'activo');
$hasSucursalActivo = poscfg_table_has_column($pdo, 'sucursales', 'activo');
$hasAlmacenActivo = poscfg_table_has_column($pdo, 'almacenes', 'activo');

function poscfg_validate_location(PDO $pdo, int $empresaId, int $sucursalId, int $almacenId): void
{
    $stmt = $pdo->prepare("SELECT id_empresa FROM sucursales WHERE id = ? LIMIT 1");
    $stmt->execute([$sucursalId]);
    $realEmpresa = (int)($stmt->fetchColumn() ?: 0);
    if ($realEmpresa !== $empresaId) {
        throw new RuntimeException('La sucursal no pertenece a la empresa seleccionada.');
    }

    $stmt = $pdo->prepare("SELECT id_sucursal FROM almacenes WHERE id = ? LIMIT 1");
    $stmt->execute([$almacenId]);
    $realSucursal = (int)($stmt->fetchColumn() ?: 0);
    if ($realSucursal !== $sucursalId) {
        throw new RuntimeException('El almacén no pertenece a la sucursal seleccionada.');
    }
}

function poscfg_process_brand_logo_upload(string $fieldName, string $baseName, array $currentConfig): string
{
    $configKey = $fieldName === 'marca_sistema_logo' ? 'marca_sistema_logo' : 'marca_empresa_logo';
    $currentPath = trim((string)($currentConfig[$configKey] ?? ''));
    $removeFlag = !empty($_POST[$fieldName . '_remove']);

    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
    foreach (array_values($allowed) as $ext) {
        $existing = __DIR__ . '/assets/img/' . $baseName . '.' . $ext;
        if ($removeFlag && file_exists($existing)) {
            @unlink($existing);
        }
    }
    if ($removeFlag) {
        return '';
    }

    if (isset($_FILES[$fieldName]) && (int)($_FILES[$fieldName]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
        $mime = mime_content_type($_FILES[$fieldName]['tmp_name']);
        if (!isset($allowed[$mime]) || (int)$_FILES[$fieldName]['size'] > 2 * 1024 * 1024) {
            throw new RuntimeException('Los logos deben ser JPG, PNG o WebP y no superar 2MB.');
        }

        foreach (array_values($allowed) as $ext) {
            $existing = __DIR__ . '/assets/img/' . $baseName . '.' . $ext;
            if (file_exists($existing)) {
                @unlink($existing);
            }
        }

        $ext = $allowed[$mime];
        $dest = __DIR__ . '/assets/img/' . $baseName . '.' . $ext;
        if (!@move_uploaded_file($_FILES[$fieldName]['tmp_name'], $dest)) {
            throw new RuntimeException('No se pudo guardar uno de los logos. Verifique permisos en assets/img/.');
        }

        return 'assets/img/' . $baseName . '.' . $ext;
    }

    return $currentPath;
}

function poscfg_current_or_post(array $currentConfig, string $key, string $fallback = ''): string
{
    if (array_key_exists($key, $_POST)) {
        return trim((string)$_POST[$key]);
    }

    return trim((string)($currentConfig[$key] ?? $fallback));
}

$msg = '';
$msgType = 'success';
$editUserId = (int)($_GET['edit_user'] ?? 0);
$activeTab = 'shop';

if ($editUserId > 0) {
    $activeTab = 'usuarios';
} elseif ((int)($_GET['edit_empresa'] ?? 0) > 0 || (int)($_GET['edit_sucursal'] ?? 0) > 0 || (int)($_GET['edit_almacen'] ?? 0) > 0) {
    $activeTab = 'estructura';
} elseif (!empty($_GET['tab'])) {
    $allowedTabs = ['shop', 'ticket', 'pantalla', 'finanzas', 'banners', 'estructura', 'usuarios', 'cajeros', 'notificaciones', 'estilo'];
    $requestedTab = (string)$_GET['tab'];
    if (in_array($requestedTab, $allowedTabs, true)) {
        $activeTab = $requestedTab;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $formAction = $_POST['form_action'] ?? '';
        $postedTab = (string)($_POST['active_tab'] ?? '');
        if (in_array($postedTab, ['shop', 'ticket', 'pantalla', 'finanzas', 'estructura', 'usuarios', 'cajeros', 'notificaciones', 'estilo'], true)) {
            $activeTab = $postedTab;
        }

        if ($formAction === 'save_empresa') {
            $activeTab = 'estructura';
            $empresaId = (int)($_POST['empresa_id'] ?? 0);
            $nombre = trim((string)($_POST['empresa_nombre'] ?? ''));
            $activo = isset($_POST['empresa_activo']) ? 1 : 0;
            if ($nombre === '') {
                throw new RuntimeException('El nombre de la empresa es obligatorio.');
            }
            if ($empresaId > 0) {
                if ($hasEmpresaActivo) {
                    $stmt = $pdo->prepare("UPDATE empresas SET nombre = ?, activo = ? WHERE id = ?");
                    $stmt->execute([$nombre, $activo, $empresaId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE empresas SET nombre = ? WHERE id = ?");
                    $stmt->execute([$nombre, $empresaId]);
                }
                $msg = 'Empresa actualizada.';
            } else {
                if ($hasEmpresaActivo) {
                    $stmt = $pdo->prepare("INSERT INTO empresas (nombre, activo) VALUES (?, ?)");
                    $stmt->execute([$nombre, $activo]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO empresas (nombre) VALUES (?)");
                    $stmt->execute([$nombre]);
                }
                $msg = 'Empresa creada.';
            }
        }

        if ($formAction === 'delete_empresa') {
            $activeTab = 'estructura';
            $empresaId = (int)($_POST['empresa_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM empresas WHERE id = ?");
            $stmt->execute([$empresaId]);
            $msg = 'Empresa eliminada.';
        }

        if ($formAction === 'save_sucursal') {
            $activeTab = 'estructura';
            $sucursalId = (int)($_POST['sucursal_id'] ?? 0);
            $empresaId = (int)($_POST['sucursal_id_empresa'] ?? 0);
            $nombre = trim((string)($_POST['sucursal_nombre'] ?? ''));
            $activo = isset($_POST['sucursal_activo']) ? 1 : 0;
            $bannerRemove = !empty($_POST['sucursal_banner_remove']);

            if ($empresaId <= 0 || $nombre === '') {
                throw new RuntimeException('La sucursal requiere empresa y nombre.');
            }

            // Procesar Banner
            $bannerPath = null;
            if ($sucursalId > 0) {
                $stmt = $pdo->prepare("SELECT imagen_banner FROM sucursales WHERE id = ?");
                $stmt->execute([$sucursalId]);
                $bannerPath = $stmt->fetchColumn();
            }

            if ($bannerRemove && $bannerPath) {
                if (file_exists(__DIR__ . '/' . $bannerPath)) @unlink(__DIR__ . '/' . $bannerPath);
                $bannerPath = null;
            }

            if (isset($_FILES['sucursal_banner']) && $_FILES['sucursal_banner']['error'] === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $type = $_FILES['sucursal_banner']['type'];
                if (!isset($allowed[$type])) throw new Exception("Formato de imagen no permitido para el banner.");
                
                $ext = $allowed[$type];
                $newBannerName = 'sucursal_banner_' . ($sucursalId ?: 'new_' . time()) . '.' . $ext;
                $target = __DIR__ . '/assets/img/' . $newBannerName;
                
                if (move_uploaded_file($_FILES['sucursal_banner']['tmp_name'], $target)) {
                    $bannerPath = 'assets/img/' . $newBannerName;
                }
            }

            if ($sucursalId > 0) {
                if ($hasSucursalActivo) {
                    $stmt = $pdo->prepare("UPDATE sucursales SET id_empresa = ?, nombre = ?, activo = ?, imagen_banner = ? WHERE id = ?");
                    $stmt->execute([$empresaId, $nombre, $activo, $bannerPath, $sucursalId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE sucursales SET id_empresa = ?, nombre = ?, imagen_banner = ? WHERE id = ?");
                    $stmt->execute([$empresaId, $nombre, $bannerPath, $sucursalId]);
                }
                $msg = 'Sucursal actualizada.';
            } else {
                if ($hasSucursalActivo) {
                    $stmt = $pdo->prepare("INSERT INTO sucursales (id_empresa, nombre, activo, imagen_banner) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$empresaId, $nombre, $activo, $bannerPath]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO sucursales (id_empresa, nombre, imagen_banner) VALUES (?, ?, ?)");
                    $stmt->execute([$empresaId, $nombre, $bannerPath]);
                }
                $msg = 'Sucursal creada.';
            }
        }

        if ($formAction === 'delete_sucursal') {
            $activeTab = 'estructura';
            $sucursalId = (int)($_POST['sucursal_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM sucursales WHERE id = ?");
            $stmt->execute([$sucursalId]);
            $msg = 'Sucursal eliminada.';
        }

        if ($formAction === 'save_almacen') {
            $activeTab = 'estructura';
            $almacenId = (int)($_POST['almacen_id'] ?? 0);
            $sucursalId = (int)($_POST['almacen_id_sucursal'] ?? 0);
            $nombre = trim((string)($_POST['almacen_nombre'] ?? ''));
            $activo = isset($_POST['almacen_activo']) ? 1 : 0;
            if ($sucursalId <= 0 || $nombre === '') {
                throw new RuntimeException('El almacén requiere sucursal y nombre.');
            }
            if ($almacenId > 0) {
                if ($hasAlmacenActivo) {
                    $stmt = $pdo->prepare("UPDATE almacenes SET id_sucursal = ?, nombre = ?, activo = ? WHERE id = ?");
                    $stmt->execute([$sucursalId, $nombre, $activo, $almacenId]);
                } else {
                    $stmt = $pdo->prepare("UPDATE almacenes SET id_sucursal = ?, nombre = ? WHERE id = ?");
                    $stmt->execute([$sucursalId, $nombre, $almacenId]);
                }
                $msg = 'Almacén actualizado.';
            } else {
                if ($hasAlmacenActivo) {
                    $stmt = $pdo->prepare("INSERT INTO almacenes (id_sucursal, nombre, activo) VALUES (?, ?, ?)");
                    $stmt->execute([$sucursalId, $nombre, $activo]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO almacenes (id_sucursal, nombre) VALUES (?, ?)");
                    $stmt->execute([$sucursalId, $nombre]);
                }
                $msg = 'Almacén creado.';
            }
        }

        if ($formAction === 'delete_almacen') {
            $activeTab = 'estructura';
            $almacenId = (int)($_POST['almacen_id'] ?? 0);
            $stmt = $pdo->prepare("DELETE FROM almacenes WHERE id = ?");
            $stmt->execute([$almacenId]);
            $msg = 'Almacén eliminado.';
        }

        if ($formAction === 'save_shop_config') {
            if ($activeTab === 'shop') {
                $activeTab = 'shop';
            }
            $newConfig = $currentConfig;
            $newConfig['tienda_nombre'] = trim((string)($_POST['tienda_nombre'] ?? ''));
            $newConfig['direccion'] = trim((string)($_POST['direccion'] ?? ''));
            $newConfig['telefono'] = trim((string)($_POST['telefono'] ?? ''));
            $newConfig['email'] = trim((string)($_POST['email'] ?? ''));
            $newConfig['website'] = trim((string)($_POST['website'] ?? ''));
            $newConfig['nit'] = trim((string)($_POST['nit'] ?? ''));
            $newConfig['cuenta_bancaria'] = trim((string)($_POST['cuenta_bancaria'] ?? ''));
            $newConfig['banco'] = trim((string)($_POST['banco'] ?? ''));
            $newConfig['mensaje_final'] = trim((string)($_POST['mensaje_final'] ?? ''));
            $newConfig['id_empresa'] = (int)($_POST['id_empresa'] ?? 1);
            $newConfig['id_sucursal'] = (int)($_POST['id_sucursal'] ?? 1);
            $newConfig['id_almacen'] = (int)($_POST['id_almacen'] ?? 1);
            $newConfig['mensajeria_tarifa_km'] = (float)($_POST['mensajeria_tarifa_km'] ?? 150);
            $newConfig['semana_inicio_dia'] = (int)($_POST['semana_inicio_dia'] ?? 1);
            $newConfig['reserva_limpieza_pct'] = (float)($_POST['reserva_limpieza_pct'] ?? 0);
            $newConfig['salario_elaborador_pct'] = (float)($_POST['salario_elaborador_pct'] ?? 0);
            $newConfig['reserva_negocio_pct'] = (float)($_POST['reserva_negocio_pct'] ?? 0);
            $newConfig['depreciacion_equipos_pct'] = (float)($_POST['depreciacion_equipos_pct'] ?? 0);
            $newConfig['mostrar_materias_primas'] = isset($_POST['mostrar_materias_primas']);
            $newConfig['mostrar_servicios'] = isset($_POST['mostrar_servicios']);
            $newConfig['kiosco_solo_stock'] = isset($_POST['kiosco_solo_stock']);
            $newConfig['customer_display_insect'] = in_array($_POST['customer_display_insect'] ?? 'mosca', ['mosca', 'mariposa', 'mariquita'], true)
                ? $_POST['customer_display_insect'] : 'mosca';
            $newConfig['customer_display_chime_type'] = trim((string)($_POST['customer_display_chime_type'] ?? 'mixkit_bell'));
            $newConfig['numero_tarjeta'] = trim((string)($_POST['numero_tarjeta'] ?? ''));
            $newConfig['titular_tarjeta'] = trim((string)($_POST['titular_tarjeta'] ?? ''));
            $newConfig['banco_tarjeta'] = trim((string)($_POST['banco_tarjeta'] ?? ''));
            $newConfig['facebook_url'] = trim((string)($_POST['facebook_url'] ?? ''));
            $newConfig['twitter_url'] = trim((string)($_POST['twitter_url'] ?? ''));
            $newConfig['instagram_url'] = trim((string)($_POST['instagram_url'] ?? ''));
            $newConfig['youtube_url'] = trim((string)($_POST['youtube_url'] ?? ''));
            $newConfig['ticket_logo'] = trim((string)($_POST['ticket_logo'] ?? ''));
            $newConfig['ticket_slogan'] = trim((string)($_POST['ticket_slogan'] ?? ''));
            $newConfig['ticket_mostrar_uuid'] = isset($_POST['ticket_mostrar_uuid']);
            $newConfig['ticket_mostrar_canal'] = isset($_POST['ticket_mostrar_canal']);
            $newConfig['ticket_mostrar_cajero'] = isset($_POST['ticket_mostrar_cajero']);
            $newConfig['ticket_mostrar_qr'] = isset($_POST['ticket_mostrar_qr']);
            $newConfig['ticket_mostrar_items_count'] = isset($_POST['ticket_mostrar_items_count']);
            $newConfig['tipo_cambio_usd'] = (float)($_POST['tipo_cambio_usd'] ?? 385);
            $newConfig['tipo_cambio_mlc'] = (float)($_POST['tipo_cambio_mlc'] ?? 310);
            $newConfig['moneda_default_pos'] = trim((string)($_POST['moneda_default_pos'] ?? 'CUP')) ?: 'CUP';
            $newConfig['marca_sistema_nombre'] = poscfg_current_or_post($currentConfig, 'marca_sistema_nombre', 'PalWeb POS Marinero');
            $newConfig['marca_empresa_nombre'] = poscfg_current_or_post($currentConfig, 'marca_empresa_nombre', (string)($currentConfig['tienda_nombre'] ?? 'MI TIENDA'));
            if ($newConfig['marca_sistema_nombre'] === '') {
                $newConfig['marca_sistema_nombre'] = 'PalWeb POS Marinero';
            }
            if ($newConfig['marca_empresa_nombre'] === '') {
                $newConfig['marca_empresa_nombre'] = (string)($currentConfig['tienda_nombre'] ?? 'MI TIENDA');
            }
            $newConfig['hero_color_1'] = trim((string)($_POST['hero_color_1'] ?? '#0f766e'));
            $newConfig['hero_color_2'] = trim((string)($_POST['hero_color_2'] ?? '#15803d'));
            $newConfig['hero_mostrar_usuario'] = isset($_POST['hero_mostrar_usuario']);
            $newConfig['hero_mostrar_logo']    = isset($_POST['hero_mostrar_logo']);
            $newConfig['categorias_ocultas'] = $_POST['categorias_ocultas'] ?? [];
            $newConfig['vapid_public_key'] = trim((string)($_POST['vapid_public_key'] ?? ($currentConfig['vapid_public_key'] ?? '')));
            $replacePrivate = isset($_POST['replace_vapid_private']);
            $newPrivate = trim((string)($_POST['vapid_private_key'] ?? ''));
            $newConfig['vapid_private_key'] = $replacePrivate ? $newPrivate : ($currentConfig['vapid_private_key'] ?? '');

            if (isset($_POST['metodo_id']) && is_array($_POST['metodo_id'])) {
                $newConfig['metodos_pago'] = [];
                $countMetodos = count($_POST['metodo_id']);
                for ($i = 0; $i < $countMetodos; $i++) {
                    $id = trim((string)($_POST['metodo_id'][$i] ?? ''));
                    $nombre = trim((string)($_POST['metodo_nombre'][$i] ?? ''));
                    if ($id === '' || $nombre === '') {
                        continue;
                    }
                    $newConfig['metodos_pago'][] = [
                        'id' => $id,
                        'nombre' => $nombre,
                        'icono' => trim((string)($_POST['metodo_icono'][$i] ?? 'fa-money-bill-wave')) ?: 'fa-money-bill-wave',
                        'color_bootstrap' => trim((string)($_POST['metodo_color'][$i] ?? 'secondary')) ?: 'secondary',
                        'activo' => in_array((string)$i, $_POST['metodo_activo'] ?? [], true),
                        'requiere_codigo' => in_array((string)$i, $_POST['metodo_requiere_codigo'] ?? [], true),
                        'aplica_pos' => in_array((string)$i, $_POST['metodo_aplica_pos'] ?? [], true),
                        'aplica_shop' => in_array((string)$i, $_POST['metodo_aplica_shop'] ?? [], true),
                        'es_transferencia' => in_array((string)$i, $_POST['metodo_es_transferencia'] ?? [], true),
                        'es_especial' => in_array((string)$i, $_POST['metodo_es_especial'] ?? [], true),
                        'texto_especial' => trim((string)($_POST['metodo_texto_especial'][$i] ?? ''))
                    ];
                }
                if (empty($newConfig['metodos_pago'])) {
                    $newConfig['metodos_pago'] = $currentConfig['metodos_pago'] ?? $defaultConfig['metodos_pago'];
                }
            } else {
                $newConfig['metodos_pago'] = $currentConfig['metodos_pago'] ?? $defaultConfig['metodos_pago'];
            }

            $newConfig['ticket_logo'] = $currentConfig['ticket_logo'] ?? '';
            if (!empty($_POST['ticket_logo_remove'])) {
                foreach (['jpg', 'png', 'gif', 'webp'] as $ext) {
                    foreach (['/assets/ticket_logo.', '/assets/img/ticket_logo.'] as $prefix) {
                        $file = __DIR__ . $prefix . $ext;
                        if (file_exists($file)) {
                            @unlink($file);
                        }
                    }
                }
                $newConfig['ticket_logo'] = '';
            } elseif (isset($_FILES['ticket_logo']) && (int)($_FILES['ticket_logo']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                $mime = mime_content_type($_FILES['ticket_logo']['tmp_name']);
                if (!isset($allowed[$mime]) || (int)$_FILES['ticket_logo']['size'] > 2 * 1024 * 1024) {
                    throw new RuntimeException('El logo debe ser JPG, PNG o WebP y no superar 2MB.');
                }
                foreach (array_values($allowed) as $ext) {
                    foreach (['/assets/ticket_logo.', '/assets/img/ticket_logo.'] as $prefix) {
                        $file = __DIR__ . $prefix . $ext;
                        if (file_exists($file)) {
                            @unlink($file);
                        }
                    }
                }
                $ext = $allowed[$mime];
                $dest = __DIR__ . '/assets/img/ticket_logo.' . $ext;
                if (!@move_uploaded_file($_FILES['ticket_logo']['tmp_name'], $dest)) {
                    throw new RuntimeException('No se pudo guardar el logo. Verifique permisos en assets/img/.');
                }
                $newConfig['ticket_logo'] = 'assets/img/ticket_logo.' . $ext;
            }

            $newConfig['marca_sistema_logo'] = poscfg_process_brand_logo_upload('marca_sistema_logo', 'branding_system_logo', $currentConfig);
            $newConfig['marca_empresa_logo'] = poscfg_process_brand_logo_upload('marca_empresa_logo', 'branding_company_logo', $currentConfig);
            $newConfig['marca_pos_login_logo'] = poscfg_process_brand_logo_upload('marca_pos_login_logo', 'branding_pos_login', $currentConfig);

            if (isset($_POST['notification_type_keys']) && is_array($_POST['notification_type_keys'])) {
                $newConfig['notification_type_settings'] = [];
                $postedNotificationKeys = $_POST['notification_type_keys'] ?? [];
                foreach ($postedNotificationKeys as $key) {
                    $newConfig['notification_type_settings'][$key] = in_array($key, $_POST['notification_type_enabled'] ?? [], true);
                }
            } else {
                $newConfig['notification_type_settings'] = $currentConfig['notification_type_settings'] ?? $defaultConfig['notification_type_settings'];
            }
            $encodedConfig = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($encodedConfig === false) {
                throw new RuntimeException('No se pudo serializar la configuración.');
            }
            if (file_put_contents($configFile, $encodedConfig) === false) {
                throw new RuntimeException('No se pudo escribir pos.cfg.');
            }
            $savedConfig = json_decode((string)file_get_contents($configFile), true);
            if (!is_array($savedConfig)) {
                throw new RuntimeException('pos.cfg quedó ilegible después de guardar.');
            }
            $currentConfig = array_merge($defaultConfig, $savedConfig);
            $msg = 'Configuración web/shop guardada.';
        }

        if ($formAction === 'save_user') {
            $activeTab = 'usuarios';
            $userId = (int)($_POST['user_id'] ?? 0);
            $nombre = trim((string)($_POST['user_nombre'] ?? ''));
            $email = trim((string)($_POST['user_email'] ?? ''));
            $pin = trim((string)($_POST['user_pin'] ?? ''));
            $rol = trim((string)($_POST['user_rol'] ?? 'cajero')) ?: 'cajero';
            $password = (string)($_POST['user_password'] ?? '');
            $empresaId = (int)($_POST['user_id_empresa'] ?? 0);
            $sucursalId = (int)($_POST['user_id_sucursal'] ?? 0);
            $almacenId = (int)($_POST['user_id_almacen'] ?? 0);
            $activo = isset($_POST['user_activo']) ? 1 : 0;

            if ($nombre === '' || $pin === '' || $empresaId <= 0 || $sucursalId <= 0 || $almacenId <= 0) {
                throw new RuntimeException('Debe completar nombre, PIN, empresa, sucursal y almacén del usuario.');
            }
            if ($userId <= 0 && trim($password) === '') {
                throw new RuntimeException('La contraseña es obligatoria al crear un usuario.');
            }
            poscfg_validate_location($pdo, $empresaId, $sucursalId, $almacenId);

            if ($userId > 0) {
                if (trim($password) !== '') {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET nombre = ?, email = ?, pin = ?, rol = ?, password = ?, id_sucursal = ?, activo = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $email !== '' ? $email : null, $pin, $rol, password_hash($password, PASSWORD_DEFAULT), $sucursalId, $activo, $userId]);
                } else {
                    $stmt = $pdo->prepare("
                        UPDATE users
                        SET nombre = ?, email = ?, pin = ?, rol = ?, id_sucursal = ?, activo = ?, updated_at = NOW()
                        WHERE id = ?
                    ");
                    $stmt->execute([$nombre, $email !== '' ? $email : null, $pin, $rol, $sucursalId, $activo, $userId]);
                }
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO users (nombre, email, password, pin, rol, id_sucursal, activo, created_at, updated_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ");
                $stmt->execute([$nombre, $email !== '' ? $email : null, password_hash($password, PASSWORD_DEFAULT), $pin, $rol, $sucursalId, $activo]);
                $userId = (int)$pdo->lastInsertId();
                $editUserId = $userId;
            }

            $stmt = $pdo->prepare("
                INSERT INTO pos_user_contexts (user_id, id_empresa, id_sucursal, id_almacen, activo)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id_empresa = VALUES(id_empresa),
                    id_sucursal = VALUES(id_sucursal),
                    id_almacen = VALUES(id_almacen),
                    activo = VALUES(activo)
            ");
            $stmt->execute([$userId, $empresaId, $sucursalId, $almacenId, $activo]);
            $msg = $userId > 0 ? 'Usuario guardado.' : 'Usuario creado.';
        }

        if ($formAction === 'save_user_context') {
            $activeTab = 'usuarios';
            $userId = (int)($_POST['user_id'] ?? 0);
            $empresaId = (int)($_POST['user_id_empresa'] ?? 0);
            $sucursalId = (int)($_POST['user_id_sucursal'] ?? 0);
            $almacenId = (int)($_POST['user_id_almacen'] ?? 0);
            $activo = isset($_POST['user_activo']) ? 1 : 0;
            if ($userId <= 0 || $empresaId <= 0 || $sucursalId <= 0 || $almacenId <= 0) {
                throw new RuntimeException('Debe seleccionar usuario, empresa, sucursal y almacén.');
            }
            poscfg_validate_location($pdo, $empresaId, $sucursalId, $almacenId);
            $stmt = $pdo->prepare("
                INSERT INTO pos_user_contexts (user_id, id_empresa, id_sucursal, id_almacen, activo)
                VALUES (?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    id_empresa = VALUES(id_empresa),
                    id_sucursal = VALUES(id_sucursal),
                    id_almacen = VALUES(id_almacen),
                    activo = VALUES(activo)
            ");
            $stmt->execute([$userId, $empresaId, $sucursalId, $almacenId, $activo]);
            $msg = 'Asignación de usuario guardada.';
        }

        if ($formAction === 'delete_user_context') {
            $activeTab = 'usuarios';
            $userId = (int)($_POST['user_id'] ?? 0);
            $pdo->prepare("DELETE FROM pos_user_contexts WHERE user_id = ?")->execute([$userId]);
            $msg = 'Asignación de usuario eliminada.';
        }

        if ($formAction === 'delete_user') {
            $activeTab = 'usuarios';
            $userId = (int)($_POST['user_id'] ?? 0);
            if ($userId === (int)($_SESSION['user_id'] ?? 0)) {
                throw new RuntimeException('No puedes eliminar tu propio usuario.');
            }
            $pdo->prepare("DELETE FROM pos_user_contexts WHERE user_id = ?")->execute([$userId]);
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$userId]);
            $msg = 'Usuario eliminado.';
            $editUserId = 0;
        }

        if ($formAction === 'save_cashier') {
            $activeTab = 'cajeros';
            $cashierId = (int)($_POST['cashier_id'] ?? 0);
            $nombre = trim((string)($_POST['cashier_nombre'] ?? ''));
            $pin = trim((string)($_POST['cashier_pin'] ?? ''));
            $rol = trim((string)($_POST['cashier_rol'] ?? 'cajero')) ?: 'cajero';
            $empresaId = (int)($_POST['cashier_id_empresa'] ?? 0);
            $sucursalId = (int)($_POST['cashier_id_sucursal'] ?? 0);
            $almacenId = (int)($_POST['cashier_id_almacen'] ?? 0);
            $activo = isset($_POST['cashier_activo']) ? 1 : 0;
            if ($nombre === '' || $pin === '' || $empresaId <= 0 || $sucursalId <= 0 || $almacenId <= 0) {
                throw new RuntimeException('Debe completar nombre, PIN y contexto del cajero.');
            }
            poscfg_validate_location($pdo, $empresaId, $sucursalId, $almacenId);
            if ($cashierId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE pos_cashiers
                    SET nombre = ?, pin = ?, rol = ?, id_empresa = ?, id_sucursal = ?, id_almacen = ?, activo = ?
                    WHERE id = ?
                ");
                $stmt->execute([$nombre, $pin, $rol, $empresaId, $sucursalId, $almacenId, $activo, $cashierId]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO pos_cashiers (nombre, pin, rol, id_empresa, id_sucursal, id_almacen, activo)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$nombre, $pin, $rol, $empresaId, $sucursalId, $almacenId, $activo]);
            }
            $msg = 'Cajero guardado.';
        }

        if ($formAction === 'delete_cashier') {
            $activeTab = 'cajeros';
            $cashierId = (int)($_POST['cashier_id'] ?? 0);
            $pdo->prepare("DELETE FROM pos_cashiers WHERE id = ?")->execute([$cashierId]);
            $msg = 'Cajero eliminado.';
        }

        if ($formAction === 'save_banners') {
            $activeTab = 'banners';
            $newConfig = $currentConfig;
            $allowedMimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
            for ($i = 0; $i < 4; $i++) {
                $newConfig['banners'][$i]['titulo']    = trim((string)($_POST['banner_titulo'][$i] ?? ($currentConfig['banners'][$i]['titulo'] ?? '')));
                $newConfig['banners'][$i]['subtitulo'] = trim((string)($_POST['banner_subtitulo'][$i] ?? ($currentConfig['banners'][$i]['subtitulo'] ?? '')));
                $newConfig['banners'][$i]['color_clase'] = $currentConfig['banners'][$i]['color_clase'] ?? 'gradient-' . ($i + 1);
                $newConfig['banners'][$i]['imagen']    = $currentConfig['banners'][$i]['imagen'] ?? '';
                $rawSize    = trim((string)($_POST['banner_bg_size'][$i] ?? 'cover'));
                $numericPart = rtrim($rawSize, '%');
                $newConfig['banners'][$i]['bg_size'] = in_array($rawSize, ['cover', 'contain'], true)
                    ? $rawSize
                    : ((is_numeric($numericPart) && (int)$numericPart >= 10 && (int)$numericPart <= 500) ? ((int)$numericPart . '%') : 'cover');
                if (!empty($_POST['banner_remove_' . $i])) {
                    $newConfig['banners'][$i]['imagen'] = '';
                } elseif (isset($_FILES['banner_file_' . $i]) && (int)$_FILES['banner_file_' . $i]['error'] === UPLOAD_ERR_OK) {
                    $mime = mime_content_type($_FILES['banner_file_' . $i]['tmp_name']);
                    if (!isset($allowedMimes[$mime]) || (int)$_FILES['banner_file_' . $i]['size'] > 2 * 1024 * 1024) {
                        throw new RuntimeException('Las imágenes de banner deben ser JPG, PNG o WebP y no superar 2MB.');
                    }
                    $ext  = $allowedMimes[$mime];
                    $dest = __DIR__ . '/assets/img/banner_custom_' . $i . '.' . $ext;
                    if (@move_uploaded_file($_FILES['banner_file_' . $i]['tmp_name'], $dest)) {
                        $newConfig['banners'][$i]['imagen'] = 'assets/img/banner_custom_' . $i . '.' . $ext;
                    }
                }
            }
            $encodedConfig = json_encode($newConfig, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
            if ($encodedConfig === false) throw new RuntimeException('No se pudo serializar la configuración.');
            if (file_put_contents($configFile, $encodedConfig) === false) throw new RuntimeException('No se pudo escribir pos.cfg.');
            $currentConfig = array_merge($defaultConfig, json_decode((string)file_get_contents($configFile), true));
            $msg = 'Banners guardados correctamente.';
        }
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        $msgType = 'danger';
    }
}

try {
    $dbCategories = $pdo->query("SELECT DISTINCT categoria FROM productos WHERE activo = 1 ORDER BY categoria ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $dbCategories = [];
}

try {
    $existingNotificationKeys = $pdo->query("SELECT DISTINCT event_key FROM push_notifications WHERE event_key <> '' ORDER BY event_key ASC")->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    $existingNotificationKeys = [];
}
$allNotificationKeys = array_values(array_unique(array_merge(array_keys($notificationTypeLabels), $existingNotificationKeys)));

$empresas = $pdo->query("SELECT id, nombre FROM empresas WHERE COALESCE(activo,1) = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$sucursales = $pdo->query("SELECT id, id_empresa, nombre FROM sucursales ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$almacenes = $pdo->query("SELECT id, id_sucursal, nombre FROM almacenes WHERE COALESCE(activo,1) = 1 ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$allEmpresas = $pdo->query("SELECT id, nombre, " . ($hasEmpresaActivo ? "COALESCE(activo,1)" : "1") . " AS activo FROM empresas ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$allSucursales = $pdo->query("SELECT id, id_empresa, nombre, imagen_banner, " . ($hasSucursalActivo ? "COALESCE(activo,1)" : "1") . " AS activo FROM sucursales ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$allAlmacenes = $pdo->query("SELECT id, id_sucursal, nombre, " . ($hasAlmacenActivo ? "COALESCE(activo,1)" : "1") . " AS activo FROM almacenes ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$users = $pdo->query("SELECT id, nombre, email, pin, rol, id_sucursal, activo FROM users ORDER BY nombre ASC")->fetchAll(PDO::FETCH_ASSOC);
$userContexts = $pdo->query("
    SELECT
        u.id AS user_id,
        u.nombre AS user_nombre,
        u.email AS user_email,
        u.pin AS user_pin,
        u.rol AS user_rol,
        u.id_sucursal AS user_sucursal_login,
        u.activo AS user_login_activo,
        puc.id_empresa,
        puc.id_sucursal,
        puc.id_almacen,
        puc.activo AS context_activo,
        e.nombre AS empresa_nombre,
        s.nombre AS sucursal_nombre,
        s.imagen_banner AS sucursal_banner,
        a.nombre AS almacen_nombre
    FROM users u
    LEFT JOIN pos_user_contexts puc ON puc.user_id = u.id
    LEFT JOIN empresas e ON e.id = puc.id_empresa
    LEFT JOIN sucursales s ON s.id = puc.id_sucursal
    LEFT JOIN almacenes a ON a.id = puc.id_almacen
    ORDER BY u.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);
$cashiers = $pdo->query("
    SELECT
        c.id,
        c.nombre,
        c.pin,
        c.rol,
        c.id_empresa,
        c.id_sucursal,
        c.id_almacen,
        c.activo,
        e.nombre AS empresa_nombre,
        s.nombre AS sucursal_nombre,
        s.imagen_banner AS sucursal_banner,
        a.nombre AS almacen_nombre
    FROM pos_cashiers c
    LEFT JOIN empresas e ON e.id = c.id_empresa
    LEFT JOIN sucursales s ON s.id = c.id_sucursal
    LEFT JOIN almacenes a ON a.id = c.id_almacen
    ORDER BY c.nombre ASC
")->fetchAll(PDO::FETCH_ASSOC);

$editUserData = [
    'id' => 0,
    'nombre' => '',
    'email' => '',
    'pin' => '',
    'rol' => 'cajero',
    'activo' => 1,
    'id_empresa' => '',
    'id_sucursal' => '',
    'id_almacen' => ''
];
if ($editUserId > 0) {
    foreach ($userContexts as $row) {
        if ((int)$row['user_id'] === $editUserId) {
            $editUserData = [
                'id' => (int)$row['user_id'],
                'nombre' => (string)$row['user_nombre'],
                'email' => (string)($row['user_email'] ?? ''),
                'pin' => (string)($row['user_pin'] ?? ''),
                'rol' => (string)$row['user_rol'],
                'activo' => (int)$row['user_login_activo'],
                'id_empresa' => (string)($row['id_empresa'] ?? ''),
                'id_sucursal' => (string)($row['id_sucursal'] ?? ''),
                'id_almacen' => (string)($row['id_almacen'] ?? '')
            ];
            break;
        }
    }
}

$editEmpresaId = (int)($_GET['edit_empresa'] ?? 0);
$editSucursalId = (int)($_GET['edit_sucursal'] ?? 0);
$editAlmacenId = (int)($_GET['edit_almacen'] ?? 0);

$editEmpresaData = ['id' => 0, 'nombre' => '', 'activo' => 1];
foreach ($allEmpresas as $empresaRow) {
    if ((int)$empresaRow['id'] === $editEmpresaId) {
        $editEmpresaData = ['id' => (int)$empresaRow['id'], 'nombre' => (string)$empresaRow['nombre'], 'activo' => (int)$empresaRow['activo']];
        break;
    }
}

$editSucursalData = ['id' => 0, 'id_empresa' => '', 'nombre' => '', 'imagen_banner' => '', 'activo' => 1];
foreach ($allSucursales as $sucursalRow) {
    if ((int)$sucursalRow['id'] === $editSucursalId) {
        $editSucursalData = [
            'id' => (int)$sucursalRow['id'],
            'id_empresa' => (string)$sucursalRow['id_empresa'],
            'nombre' => (string)$sucursalRow['nombre'],
            'imagen_banner' => (string)($sucursalRow['imagen_banner'] ?? ''),
            'activo' => (int)$sucursalRow['activo']
        ];
        break;
    }
}

$editAlmacenData = ['id' => 0, 'id_sucursal' => '', 'nombre' => '', 'activo' => 1];
foreach ($allAlmacenes as $almacenRow) {
    if ((int)$almacenRow['id'] === $editAlmacenId) {
        $editAlmacenData = [
            'id' => (int)$almacenRow['id'],
            'id_sucursal' => (string)$almacenRow['id_sucursal'],
            'nombre' => (string)$almacenRow['nombre'],
            'activo' => (int)$almacenRow['activo']
        ];
        break;
    }
}

$treeCompanies = [];
foreach ($allEmpresas as $empresaRow) {
    $treeCompanies[(int)$empresaRow['id']] = ['empresa' => $empresaRow, 'sucursales' => []];
}
foreach ($allSucursales as $sucursalRow) {
    $empresaId = (int)$sucursalRow['id_empresa'];
    if (!isset($treeCompanies[$empresaId])) {
        continue;
    }
    $treeCompanies[$empresaId]['sucursales'][(int)$sucursalRow['id']] = ['sucursal' => $sucursalRow, 'almacenes' => []];
}
foreach ($allAlmacenes as $almacenRow) {
    $sucursalId = (int)$almacenRow['id_sucursal'];
    foreach ($treeCompanies as &$treeCompany) {
        if (isset($treeCompany['sucursales'][$sucursalId])) {
            $treeCompany['sucursales'][$sucursalId]['almacenes'][] = $almacenRow;
            break;
        }
    }
}
unset($treeCompany);
?>
<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>POS Config dinámico</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root {
            --cfg-bg:#eef3f8;
            --cfg-surface:#ffffff;
            --cfg-border:#dbe3ee;
            --cfg-shadow:0 18px 45px rgba(15,23,42,.08);
            --cfg-shadow-soft:0 8px 22px rgba(15,23,42,.06);
            --cfg-primary:#1d4ed8;
            --cfg-accent:#0f766e;
            --cfg-text:#0f172a;
            --cfg-muted:#64748b;
        }
        body { background:linear-gradient(180deg,#edf3f9 0%,#f7f9fc 100%); font-family:'Segoe UI',sans-serif; color:var(--cfg-text); }
        .page-shell { background:rgba(255,255,255,.72); border:1px solid rgba(255,255,255,.85); box-shadow:var(--cfg-shadow); border-radius:28px; padding:24px; backdrop-filter:blur(8px); }
        .hero-panel { background:linear-gradient(135deg,#020617 0%,#0f172a 38%,#172554 70%,#134e4a 100%); color:#fff; border-radius:24px; padding:24px 26px; box-shadow:0 22px 50px rgba(2,6,23,.28); margin-bottom:22px; position:relative; overflow:hidden; }
        .hero-panel:before { content:""; position:absolute; inset:auto -80px -80px auto; width:220px; height:220px; background:radial-gradient(circle,rgba(255,255,255,.16),transparent 70%); }
        .hero-kpi { background:rgba(255,255,255,.1); border:1px solid rgba(255,255,255,.14); border-radius:16px; padding:14px 16px; min-width:150px; }
        .card { border:1px solid var(--cfg-border); box-shadow:var(--cfg-shadow-soft); margin-bottom:20px; border-radius:20px; overflow:hidden; background:var(--cfg-surface); }
        .card-header { background:linear-gradient(180deg,#ffffff 0%,#f8fbff 100%); border-bottom:1px solid #eef2f7; font-weight:700; padding:14px 18px; }
        .table td,.table th { vertical-align:middle; }
        .mono { font-family:ui-monospace, SFMono-Regular, Menlo, monospace; }
        .small-muted { font-size:.82rem; color:var(--cfg-muted); }
        .metodo-item.dragging { opacity:.55; }
        .metodo-item { background:#f8fafc; border-radius:14px; box-shadow:inset 0 0 0 1px #dde6f0; padding:12px; }
        .metodo-warn { font-size:.82rem; color:#b45309; display:none; }
        .metodo-legacy-alert { background:#fff7ed; border:0; color:#9a3412; border-radius:14px; padding:12px 14px; }
        .metodo-item .form-label.small { margin-bottom:.2rem; color:#64748b; font-weight:600; }
        .metodo-item .form-check-inline { margin-right:.75rem; }
        .metodo-item .especial-text-row { margin-top:.6rem; }
        #cfgTabs { border:none; flex-wrap:wrap; gap:.55rem; margin-bottom:1.25rem; }
        #cfgTabs .nav-link { color:#334155; font-weight:700; border:1px solid #dbe3ee; padding:.75rem 1rem; border-radius:999px; background:#ffffff; box-shadow:0 4px 12px rgba(15,23,42,.04); }
        #cfgTabs .nav-link:hover { color:var(--cfg-primary); border-color:#bfd3ff; background:#f8fbff; }
        #cfgTabs .nav-link.active { color:#fff; border-color:transparent; background:linear-gradient(135deg,#2563eb,#0f766e); box-shadow:0 12px 26px rgba(37,99,235,.22); }
        .insect-card { border:2px solid #dee2e6; border-radius:14px; padding:10px 14px; cursor:pointer; transition:border-color .2s, background .2s; background:white; width:120px; text-align:center; }
        .insect-option input:checked + .insect-card { border-color:#0d6efd; background:#eef3ff; }
        .insect-option:hover .insect-card { border-color:#6ea8fe; }
        .tree-node { border:1px solid #dbe3ee; border-radius:18px; background:#fff; box-shadow:0 10px 24px rgba(15,23,42,.05); padding:16px; margin-bottom:14px; }
        .tree-branch { margin-left:18px; padding-left:16px; border-left:3px solid #dbeafe; margin-top:12px; }
        .tree-pill { display:inline-flex; align-items:center; gap:8px; background:#eff6ff; color:#1d4ed8; border:1px solid #cfe0ff; border-radius:999px; padding:5px 10px; font-size:.82rem; font-weight:700; }
        .section-note { background:#f8fbff; border:1px dashed #cbd5e1; border-radius:16px; padding:14px 16px; color:#475569; }
        .form-control,.form-select { border-radius:12px; border-color:#d7e0ea; padding:.7rem .85rem; }
        .btn { border-radius:12px; font-weight:700; }
        .table thead th { color:#475569; font-size:.83rem; text-transform:uppercase; letter-spacing:.04em; background:#f8fbff; }
        .notification-switch { position:relative; display:flex; align-items:flex-start; gap:12px; border:1px solid #dbe3ee; border-radius:16px; padding:14px 16px; background:#fff; min-height:92px; box-shadow:0 6px 18px rgba(15,23,42,.04); }
        .notification-switch .form-check-input { margin-top:4px; flex:0 0 auto; }
        .notification-switch .switch-copy { min-width:0; }
        .notification-switch .switch-title { display:block; font-weight:700; color:#0f172a; line-height:1.25; }
        .notification-switch .switch-key { display:block; margin-top:4px; font-size:.82rem; color:#64748b; word-break:break-word; }
        .brand-preview { border:1px dashed #cbd5e1; border-radius:18px; background:linear-gradient(180deg,#f8fbff 0%,#ffffff 100%); padding:18px; height:100%; }
        .brand-logo-preview { width:88px; height:88px; border-radius:20px; border:1px solid #dbe3ee; background:#fff; display:flex; align-items:center; justify-content:center; overflow:hidden; box-shadow:0 8px 20px rgba(15,23,42,.06); }
        .brand-logo-preview img { width:100%; height:100%; object-fit:contain; }
        .brand-logo-placeholder { color:#94a3b8; font-size:1.85rem; }
        @media (max-width: 768px) {
            body { padding:12px !important; }
            .page-shell { padding:14px; border-radius:18px; }
            .hero-panel { padding:18px; border-radius:18px; }
            .hero-kpi { min-width:unset; width:100%; }
        }

        /* ── PREMIUM SIDEBAR ───────────────────────────── */
        :root {
            --sidebar-width: 260px;
            --sidebar-collapsed-width: 72px;
            --sidebar-bg: #0f172a;
        }
        body { overflow-x: hidden; }
        #sidebar {
            width: var(--sidebar-width);
            height: 100vh;
            position: fixed;
            left: 0; top: 0;
            background: var(--sidebar-bg);
            color: #fff;
            transition: width 0.3s cubic-bezier(.4,0,.2,1);
            z-index: 1040;
            box-shadow: 10px 0 30px rgba(0,0,0,.15);
            display: flex;
            flex-direction: column;
            overflow: hidden;
        }
        #sidebar.collapsed { width: var(--sidebar-collapsed-width); }
        #sidebar.collapsed .nav-text,
        #sidebar.collapsed .sidebar-brand-text { display: none !important; }
        #sidebar.collapsed .sidebar-nav-link { justify-content: center; padding: .8rem 0; }
        .sidebar-header {
            padding: 1.2rem 1.4rem;
            border-bottom: 1px solid rgba(255,255,255,.1);
            flex-shrink: 0;
        }
        .sidebar-nav-scroll { overflow-y: auto; overflow-x: hidden; flex: 1; }
        .sidebar-nav-link {
            color: rgba(255,255,255,.7);
            padding: .75rem 1.4rem;
            display: flex;
            align-items: center;
            gap: .85rem;
            transition: all .2s;
            border-left: 3px solid transparent;
            cursor: pointer;
            text-decoration: none;
            white-space: nowrap;
            font-size: .9rem;
        }
        .sidebar-nav-link:hover, .sidebar-nav-link.active {
            color: #fff;
            background: rgba(255,255,255,.07);
            border-left-color: var(--cfg-primary);
        }
        .sidebar-nav-link i { font-size: 1.1rem; min-width: 20px; text-align: center; flex-shrink: 0; }
        .sidebar-footer {
            border-top: 1px solid rgba(255,255,255,.1);
            padding: .75rem 1.4rem;
            flex-shrink: 0;
        }
        #main-content {
            margin-left: var(--sidebar-width);
            transition: margin-left 0.3s cubic-bezier(.4,0,.2,1);
            min-height: 100vh;
            padding: 1.75rem 2rem;
        }
        #main-content.expanded { margin-left: var(--sidebar-collapsed-width); }
        .cfg-topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1.5rem;
            flex-wrap: wrap;
            gap: .75rem;
        }
        .btn-premium {
            background: var(--cfg-primary);
            color: white;
            border-radius: .8rem;
            padding: .55rem 1.4rem;
            font-weight: 700;
            border: none;
            transition: all .2s;
        }
        .btn-premium:hover { background: #1e40af; transform: translateY(-1px); color: #fff; }

        /* ── BANNERS ────────────────────────────────────── */
        .banner-edit-card {
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            padding: 1rem;
            background: #fdfdfd;
            margin-bottom: 1rem;
        }
        .banner-preview {
            height: 80px;
            border-radius: .5rem;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #fff;
            font-weight: bold;
            margin-top: 10px;
            background-size: cover;
            background-position: center;
        }
        .gradient-1 { background: linear-gradient(135deg,#667eea 0%,#764ba2 100%); }
        .gradient-2 { background: linear-gradient(135deg,#ff9a9e 0%,#fecfef 100%); color: #7c3aed; }
        .gradient-3 { background: linear-gradient(135deg,#84fab0 0%,#8fd3f4 100%); color: #065f46; }
        .gradient-4 { background: linear-gradient(135deg,#fccb90 0%,#d57eeb 100%); }

        @media (max-width: 991px) {
            #sidebar { width: 0; }
            #sidebar.mobile-open { width: var(--sidebar-width); }
            #main-content { margin-left: 0 !important; padding: 1rem; }
        }
    </style>
</head>
<body>

<!-- ╔═══════════════════════════════╗ -->
<!-- ║   SIDEBAR PREMIUM             ║ -->
<!-- ╚═══════════════════════════════╝ -->
<nav id="sidebar">
    <div class="sidebar-header d-flex align-items-center gap-2">
        <i class="fas fa-layer-group text-primary fs-5"></i>
        <span class="sidebar-brand-text fw-bold fs-6">POS Config</span>
    </div>
    <div class="sidebar-nav-scroll">
        <a class="sidebar-nav-link" data-tab="shop"          onclick="showCfgTab('shop', this)"><i class="fas fa-store"></i>          <span class="nav-text">🏪 Negocio</span></a>
        <a class="sidebar-nav-link" data-tab="ticket"        onclick="showCfgTab('ticket', this)"><i class="fas fa-receipt"></i>       <span class="nav-text">🧾 Ticket</span></a>
        <a class="sidebar-nav-link" data-tab="pantalla"      onclick="showCfgTab('pantalla', this)"><i class="fas fa-tv"></i>          <span class="nav-text">🖥️ Pantalla</span></a>
        <a class="sidebar-nav-link" data-tab="finanzas"      onclick="showCfgTab('finanzas', this)"><i class="fas fa-credit-card"></i> <span class="nav-text">💳 Pagos y Redes</span></a>
        <a class="sidebar-nav-link" data-tab="banners"       onclick="showCfgTab('banners', this)"><i class="fas fa-image"></i>        <span class="nav-text">🖼️ Banners Shop</span></a>
        <a class="sidebar-nav-link" data-tab="estructura"    onclick="showCfgTab('estructura', this)"><i class="fas fa-building"></i>  <span class="nav-text">🏢 Estructura</span></a>
        <a class="sidebar-nav-link" data-tab="usuarios"      onclick="showCfgTab('usuarios', this)"><i class="fas fa-user"></i>        <span class="nav-text">👤 Usuarios ERP</span></a>
        <a class="sidebar-nav-link" data-tab="cajeros"       onclick="showCfgTab('cajeros', this)"><i class="fas fa-cash-register"></i><span class="nav-text">🧑‍💼 Cajeros POS</span></a>
        <a class="sidebar-nav-link" data-tab="notificaciones"onclick="showCfgTab('notificaciones', this)"><i class="fas fa-bell"></i>  <span class="nav-text">🔔 Notificaciones</span></a>
        <a class="sidebar-nav-link" data-tab="estilo"        onclick="showCfgTab('estilo', this)"><i class="fas fa-palette"></i>       <span class="nav-text">🎨 Identidad</span></a>
    </div>
    <div class="sidebar-footer">
        <a href="dashboard.php" class="sidebar-nav-link px-0"><i class="fas fa-arrow-left"></i><span class="nav-text">Volver al Dashboard</span></a>
    </div>
</nav>

<!-- ╔═══════════════════════════════╗ -->
<!-- ║   MAIN CONTENT                ║ -->
<!-- ╚═══════════════════════════════╝ -->
<main id="main-content">

    <!-- Topbar -->
    <div class="cfg-topbar">
        <div class="d-flex align-items-center gap-3">
            <button class="btn btn-sm btn-light border" onclick="toggleSidebar()" title="Colapsar menú"><i class="fas fa-bars"></i></button>
            <div>
                <h4 class="mb-0 fw-bold">Configuración del Sistema</h4>
                <div class="small text-muted">shop.php usa pos.cfg · POS y ERP usan contexto por cajero/login</div>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <span class="badge bg-light text-dark border p-2"><i class="fas fa-building me-1"></i><?php echo count(array_filter($allEmpresas, fn($e) => !empty($e['activo']))); ?> empresas</span>
            <span class="badge bg-light text-dark border p-2"><i class="fas fa-users me-1"></i><?php echo count($userContexts); ?> usuarios</span>
            <span class="badge bg-light text-dark border p-2"><i class="fas fa-cash-register me-1"></i><?php echo count($cashiers); ?> cajeros</span>
        </div>
    </div>

    <?php if ($msg !== ''): ?>
        <div class="alert alert-<?php echo $msgType; ?> shadow-sm"><?php echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="cfg-tab" data-tab-panel="shop">
        <form method="post">
            <input type="hidden" name="form_action" value="save_shop_config">
            <input type="hidden" name="marca_sistema_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_sistema_nombre'] ?? 'PalWeb POS Marinero'); ?>">
            <input type="hidden" name="marca_empresa_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_empresa_nombre'] ?? ($currentConfig['tienda_nombre'] ?? 'MI TIENDA')); ?>">
            <div class="card">
                <div class="card-header fw-bold text-primary">🏪 Datos del negocio</div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><label class="form-label">Nombre</label><input type="text" name="tienda_nombre" class="form-control" value="<?php echo htmlspecialchars($currentConfig['tienda_nombre']); ?>" required></div>
                    <div class="col-md-6"><label class="form-label">Teléfono</label><input type="text" name="telefono" class="form-control" value="<?php echo htmlspecialchars($currentConfig['telefono']); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Email</label><input type="text" name="email" class="form-control" value="<?php echo htmlspecialchars($currentConfig['email']); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Website</label><input type="text" name="website" class="form-control" value="<?php echo htmlspecialchars($currentConfig['website']); ?>"></div>
                    <div class="col-12"><label class="form-label">Dirección</label><input type="text" name="direccion" class="form-control" value="<?php echo htmlspecialchars($currentConfig['direccion']); ?>"></div>
                    <div class="col-md-6"><label class="form-label">NIT</label><input type="text" name="nit" class="form-control" value="<?php echo htmlspecialchars($currentConfig['nit']); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Banco negocio</label><input type="text" name="banco" class="form-control" value="<?php echo htmlspecialchars($currentConfig['banco']); ?>"></div>
                    <div class="col-12"><label class="form-label">Cuenta bancaria</label><input type="text" name="cuenta_bancaria" class="form-control" value="<?php echo htmlspecialchars($currentConfig['cuenta_bancaria']); ?>"></div>
                    <div class="col-12"><label class="form-label">Mensaje ticket</label><input type="text" name="mensaje_final" class="form-control" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header fw-bold text-secondary">🌐 Alcance web y contexto de shop</div>
                <div class="card-body row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Empresa shop/web</label>
                        <select class="form-select" id="shopEmpresaSelect" name="id_empresa">
                            <?php foreach ($empresas as $empresa): ?>
                                <option value="<?php echo (int)$empresa['id']; ?>" <?php echo (int)$currentConfig['id_empresa'] === (int)$empresa['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($empresa['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sucursal shop/web</label>
                        <select class="form-select" id="shopSucursalSelect" name="id_sucursal">
                            <?php foreach ($sucursales as $sucursal): ?>
                                <option value="<?php echo (int)$sucursal['id']; ?>" data-empresa-id="<?php echo (int)$sucursal['id_empresa']; ?>" <?php echo (int)$currentConfig['id_sucursal'] === (int)$sucursal['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sucursal['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Almacén shop/web</label>
                        <select class="form-select" id="shopAlmacenSelect" name="id_almacen">
                            <?php foreach ($almacenes as $almacen): ?>
                                <option value="<?php echo (int)$almacen['id']; ?>" data-sucursal-id="<?php echo (int)$almacen['id_sucursal']; ?>" <?php echo (int)$currentConfig['id_almacen'] === (int)$almacen['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($almacen['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3"><label class="form-label">Tarifa km</label><input type="number" step="0.01" name="mensajeria_tarifa_km" class="form-control" value="<?php echo htmlspecialchars((string)$currentConfig['mensajeria_tarifa_km']); ?>"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header fw-bold text-success">⚙️ Preferencias operativas</div>
                <div class="card-body row g-3">
                    <div class="col-md-3"><label class="form-label">Inicio semana</label><input type="number" min="0" max="6" name="semana_inicio_dia" class="form-control" value="<?php echo (int)$currentConfig['semana_inicio_dia']; ?>"></div>
                    <div class="col-md-3"><label class="form-label">Limpieza %</label><input type="number" step="0.01" name="reserva_limpieza_pct" class="form-control" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_limpieza_pct']); ?>"></div>
                    <div class="col-md-3"><label class="form-label">Salario elaborador %</label><input type="number" step="0.01" name="salario_elaborador_pct" class="form-control" value="<?php echo htmlspecialchars((string)$currentConfig['salario_elaborador_pct']); ?>"></div>
                    <div class="col-md-3"><label class="form-label">Reserva negocio %</label><input type="number" step="0.01" name="reserva_negocio_pct" class="form-control" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_negocio_pct']); ?>"></div>
                    <div class="col-md-3"><label class="form-label">Depreciación equipos %</label><input type="number" step="0.01" name="depreciacion_equipos_pct" class="form-control" value="<?php echo htmlspecialchars((string)$currentConfig['depreciacion_equipos_pct']); ?>"></div>
                    <div class="col-md-6 form-check ms-2"><input class="form-check-input" type="checkbox" id="mostrar_materias_primas" name="mostrar_materias_primas" <?php echo !empty($currentConfig['mostrar_materias_primas']) ? 'checked' : ''; ?>><label class="form-check-label" for="mostrar_materias_primas">Mostrar materias primas</label></div>
                    <div class="col-md-6 form-check ms-2"><input class="form-check-input" type="checkbox" id="mostrar_servicios" name="mostrar_servicios" <?php echo !empty($currentConfig['mostrar_servicios']) ? 'checked' : ''; ?>><label class="form-check-label" for="mostrar_servicios">Mostrar servicios</label></div>
                    <div class="col-md-6 form-check ms-2"><input class="form-check-input" type="checkbox" id="kiosco_solo_stock" name="kiosco_solo_stock" <?php echo !empty($currentConfig['kiosco_solo_stock']) ? 'checked' : ''; ?>><label class="form-check-label" for="kiosco_solo_stock">Kiosco solo con stock</label></div>
                    <div class="col-md-6 form-check ms-2"><input class="form-check-input" type="checkbox" id="customer_display_insect" name="customer_display_insect" <?php echo !empty($currentConfig['customer_display_insect']) ? 'checked' : ''; ?>><label class="form-check-label" for="customer_display_insect">Display cliente con insecto</label></div>
                    <div class="col-md-6"><label class="form-label">Chime display cliente</label><input type="text" name="customer_display_chime_type" class="form-control" value="<?php echo htmlspecialchars($currentConfig['customer_display_chime_type']); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Moneda default POS</label><select class="form-select" name="moneda_default_pos"><option value="CUP" <?php echo ($currentConfig['moneda_default_pos'] ?? 'CUP') === 'CUP' ? 'selected' : ''; ?>>CUP</option><option value="USD" <?php echo ($currentConfig['moneda_default_pos'] ?? '') === 'USD' ? 'selected' : ''; ?>>USD</option><option value="MLC" <?php echo ($currentConfig['moneda_default_pos'] ?? '') === 'MLC' ? 'selected' : ''; ?>>MLC</option></select></div>
                    <div class="col-md-3"><label class="form-label">Cambio USD</label><input type="number" step="0.01" name="tipo_cambio_usd" class="form-control" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_usd']); ?>"></div>
                    <div class="col-md-3"><label class="form-label">Cambio MLC</label><input type="number" step="0.01" name="tipo_cambio_mlc" class="form-control" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_mlc']); ?>"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header fw-bold text-warning">🗂️ Categorías visibles</div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <label class="form-label">Categorías ocultas</label>
                        <select class="form-select" name="categorias_ocultas[]" multiple size="6">
                            <?php foreach ($dbCategories as $category): ?>
                                <option value="<?php echo htmlspecialchars($category); ?>" <?php echo in_array($category, $currentConfig['categorias_ocultas'] ?? [], true) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($category); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100 shadow">Guardar configuración web/shop</button>
        </form>
    </div>

    <div class="cfg-tab d-none" data-tab-panel="finanzas">
        <form method="post">
            <input type="hidden" name="form_action" value="save_shop_config">
            <input type="hidden" name="marca_sistema_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_sistema_nombre'] ?? 'PalWeb POS Marinero'); ?>">
            <input type="hidden" name="marca_empresa_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_empresa_nombre'] ?? ($currentConfig['tienda_nombre'] ?? 'MI TIENDA')); ?>">
            <input type="hidden" name="tienda_nombre" value="<?php echo htmlspecialchars($currentConfig['tienda_nombre']); ?>">
            <input type="hidden" name="direccion" value="<?php echo htmlspecialchars($currentConfig['direccion']); ?>">
            <input type="hidden" name="telefono" value="<?php echo htmlspecialchars($currentConfig['telefono']); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($currentConfig['email']); ?>">
            <input type="hidden" name="website" value="<?php echo htmlspecialchars($currentConfig['website']); ?>">
            <input type="hidden" name="nit" value="<?php echo htmlspecialchars($currentConfig['nit']); ?>">
            <input type="hidden" name="cuenta_bancaria" value="<?php echo htmlspecialchars($currentConfig['cuenta_bancaria']); ?>">
            <input type="hidden" name="banco" value="<?php echo htmlspecialchars($currentConfig['banco']); ?>">
            <input type="hidden" name="mensaje_final" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>">
            <input type="hidden" name="id_empresa" value="<?php echo (int)$currentConfig['id_empresa']; ?>">
            <input type="hidden" name="id_sucursal" value="<?php echo (int)$currentConfig['id_sucursal']; ?>">
            <input type="hidden" name="id_almacen" value="<?php echo (int)$currentConfig['id_almacen']; ?>">
            <input type="hidden" name="mensajeria_tarifa_km" value="<?php echo htmlspecialchars((string)$currentConfig['mensajeria_tarifa_km']); ?>">
            <input type="hidden" name="semana_inicio_dia" value="<?php echo (int)$currentConfig['semana_inicio_dia']; ?>">
            <input type="hidden" name="reserva_limpieza_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_limpieza_pct']); ?>">
            <input type="hidden" name="salario_elaborador_pct" value="<?php echo htmlspecialchars((string)$currentConfig['salario_elaborador_pct']); ?>">
            <input type="hidden" name="reserva_negocio_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_negocio_pct']); ?>">
            <input type="hidden" name="depreciacion_equipos_pct" value="<?php echo htmlspecialchars((string)$currentConfig['depreciacion_equipos_pct']); ?>">
            <?php if (!empty($currentConfig['mostrar_materias_primas'])): ?><input type="hidden" name="mostrar_materias_primas" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['mostrar_servicios'])): ?><input type="hidden" name="mostrar_servicios" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['kiosco_solo_stock'])): ?><input type="hidden" name="kiosco_solo_stock" value="1"><?php endif; ?>
            <input type="hidden" name="customer_display_insect" value="<?php echo htmlspecialchars($currentConfig['customer_display_insect'] ?? 'mosca'); ?>">
            <input type="hidden" name="customer_display_chime_type" value="<?php echo htmlspecialchars($currentConfig['customer_display_chime_type']); ?>">
            <input type="hidden" name="tipo_cambio_usd" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_usd']); ?>">
            <input type="hidden" name="tipo_cambio_mlc" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_mlc']); ?>">
            <input type="hidden" name="moneda_default_pos" value="<?php echo htmlspecialchars($currentConfig['moneda_default_pos']); ?>">
            <?php foreach (($currentConfig['categorias_ocultas'] ?? []) as $hiddenCategory): ?><input type="hidden" name="categorias_ocultas[]" value="<?php echo htmlspecialchars($hiddenCategory); ?>"><?php endforeach; ?>
            <?php foreach (($currentConfig['notification_type_settings'] ?? []) as $key => $enabled): ?>
                <input type="hidden" name="notification_type_keys[]" value="<?php echo htmlspecialchars($key); ?>">
                <?php if ($enabled): ?><input type="hidden" name="notification_type_enabled[]" value="<?php echo htmlspecialchars($key); ?>"><?php endif; ?>
            <?php endforeach; ?>

            <div class="card">
                <div class="card-header fw-bold text-danger">💳 Datos de cobro</div>
                <div class="card-body row g-3">
                    <div class="col-md-4"><label class="form-label">Número tarjeta</label><input type="text" name="numero_tarjeta" class="form-control" value="<?php echo htmlspecialchars($currentConfig['numero_tarjeta']); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Titular tarjeta</label><input type="text" name="titular_tarjeta" class="form-control" value="<?php echo htmlspecialchars($currentConfig['titular_tarjeta']); ?>"></div>
                    <div class="col-md-4"><label class="form-label">Banco tarjeta</label><input type="text" name="banco_tarjeta" class="form-control" value="<?php echo htmlspecialchars($currentConfig['banco_tarjeta']); ?>"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header fw-bold text-secondary">📣 Redes sociales</div>
                <div class="card-body row g-3">
                    <div class="col-md-6"><label class="form-label">Facebook URL</label><input type="text" name="facebook_url" class="form-control" value="<?php echo htmlspecialchars($currentConfig['facebook_url']); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Twitter URL</label><input type="text" name="twitter_url" class="form-control" value="<?php echo htmlspecialchars($currentConfig['twitter_url']); ?>"></div>
                    <div class="col-md-6"><label class="form-label">Instagram URL</label><input type="text" name="instagram_url" class="form-control" value="<?php echo htmlspecialchars($currentConfig['instagram_url']); ?>"></div>
                    <div class="col-md-6"><label class="form-label">YouTube URL</label><input type="text" name="youtube_url" class="form-control" value="<?php echo htmlspecialchars($currentConfig['youtube_url']); ?>"></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header fw-bold text-dark">🔐 Web Push VAPID</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">VAPID public key</label>
                        <textarea class="form-control mono" name="vapid_public_key" rows="3" readonly><?php echo htmlspecialchars($currentConfig['vapid_public_key'] ?? ''); ?></textarea>
                        <div class="form-text">Se muestra en solo lectura para evitar cambios accidentales.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">VAPID private key</label>
                        <textarea class="form-control mono" name="vapid_private_key" rows="3" placeholder="Pega una nueva clave solo si vas a reemplazar la actual"></textarea>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="replace_vapid_private" name="replace_vapid_private">
                            <label class="form-check-label" for="replace_vapid_private">Reemplazar clave privada VAPID actual</label>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header fw-bold text-primary">💸 Métodos de pago</div>
                <div class="card-body row g-3">
                    <div class="col-12">
                        <div class="metodo-legacy-alert small mb-3">
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            El campo <strong>ID</strong> se guarda en base de datos. No lo cambies en métodos existentes. Solo un método debería marcarse como <strong>Transferencia</strong>.
                        </div>
                        <div id="metodosPagoList">
                            <?php foreach (($currentConfig['metodos_pago'] ?? []) as $idx => $metodo): ?>
                                <div class="metodo-item border rounded mb-2" draggable="true">
                                    <div class="row g-2">
                                        <div class="col-md-2">
                                            <label class="form-label small">ID (inmutable)</label>
                                            <input type="text" class="form-control form-control-sm mono fw-bold" name="metodo_id[]" value="<?php echo htmlspecialchars($metodo['id'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">Nombre visible</label>
                                            <input type="text" class="form-control form-control-sm" name="metodo_nombre[]" value="<?php echo htmlspecialchars($metodo['nombre'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">Icono FontAwesome</label>
                                            <input type="text" class="form-control form-control-sm" name="metodo_icono[]" value="<?php echo htmlspecialchars($metodo['icono'] ?? ''); ?>">
                                        </div>
                                        <div class="col-md-2">
                                            <label class="form-label small">Color</label>
                                            <select class="form-select form-select-sm" name="metodo_color[]">
                                                <?php foreach (['success'=>'Verde','primary'=>'Azul','secondary'=>'Gris','warning'=>'Amarillo','danger'=>'Rojo','info'=>'Celeste','dark'=>'Negro'] as $cv => $cl): ?>
                                                    <option value="<?php echo $cv; ?>" <?php echo ($metodo['color_bootstrap'] ?? '') === $cv ? 'selected' : ''; ?>><?php echo $cl; ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <label class="form-label small">Opciones</label>
                                            <div class="d-flex flex-wrap gap-2 align-items-center">
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" name="metodo_activo[]" value="<?php echo $idx; ?>" id="metodo_activo_<?php echo $idx; ?>" <?php echo !empty($metodo['activo']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="metodo_activo_<?php echo $idx; ?>">Activo</label>
                                                </div>
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" name="metodo_aplica_pos[]" value="<?php echo $idx; ?>" id="metodo_pos_<?php echo $idx; ?>" <?php echo !empty($metodo['aplica_pos']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="metodo_pos_<?php echo $idx; ?>">POS</label>
                                                </div>
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" name="metodo_aplica_shop[]" value="<?php echo $idx; ?>" id="metodo_shop_<?php echo $idx; ?>" <?php echo !empty($metodo['aplica_shop']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="metodo_shop_<?php echo $idx; ?>">Shop</label>
                                                </div>
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" name="metodo_es_transferencia[]" value="<?php echo $idx; ?>" id="metodo_trans_<?php echo $idx; ?>" <?php echo !empty($metodo['es_transferencia']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="metodo_trans_<?php echo $idx; ?>">Transf.</label>
                                                </div>
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" name="metodo_es_especial[]" value="<?php echo $idx; ?>" id="metodo_esp_<?php echo $idx; ?>" <?php echo !empty($metodo['es_especial']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small text-info fw-semibold" for="metodo_esp_<?php echo $idx; ?>">Especial</label>
                                                </div>
                                                <div class="form-check form-check-inline mb-0">
                                                    <input class="form-check-input" type="checkbox" name="metodo_requiere_codigo[]" value="<?php echo $idx; ?>" id="metodo_cod_<?php echo $idx; ?>" <?php echo !empty($metodo['requiere_codigo']) ? 'checked' : ''; ?>>
                                                    <label class="form-check-label small" for="metodo_cod_<?php echo $idx; ?>">Código</label>
                                                </div>
                                                <div class="d-flex gap-2 ms-auto">
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveMetodoPago(this, -1)">↑</button>
                                                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveMetodoPago(this, 1)">↓</button>
                                                    <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.metodo-item').remove()"><i class="fas fa-trash"></i></button>
                                                </div>
                                            </div>
                                            <div class="especial-text-row">
                                                <input type="text" class="form-control form-control-sm" name="metodo_texto_especial[]" placeholder="Texto a mostrar al seleccionar este método" value="<?php echo htmlspecialchars($metodo['texto_especial'] ?? ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md-12 metodo-warn"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" class="btn btn-outline-primary btn-sm mt-2" onclick="addMetodoPagoRow()">
                            <i class="fas fa-plus-circle me-1"></i>Agregar Método de Pago
                        </button>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-danger btn-lg w-100 shadow">Guardar finanzas / ticket / redes</button>
        </form>
    </div>

    <div class="cfg-tab d-none" data-tab-panel="ticket">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="form_action" value="save_shop_config">
            <input type="hidden" name="marca_sistema_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_sistema_nombre'] ?? 'PalWeb POS Marinero'); ?>">
            <input type="hidden" name="marca_empresa_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_empresa_nombre'] ?? ($currentConfig['tienda_nombre'] ?? 'MI TIENDA')); ?>">
            <input type="hidden" name="tienda_nombre" value="<?php echo htmlspecialchars($currentConfig['tienda_nombre']); ?>">
            <input type="hidden" name="direccion" value="<?php echo htmlspecialchars($currentConfig['direccion']); ?>">
            <input type="hidden" name="telefono" value="<?php echo htmlspecialchars($currentConfig['telefono']); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($currentConfig['email']); ?>">
            <input type="hidden" name="website" value="<?php echo htmlspecialchars($currentConfig['website']); ?>">
            <input type="hidden" name="nit" value="<?php echo htmlspecialchars($currentConfig['nit']); ?>">
            <input type="hidden" name="cuenta_bancaria" value="<?php echo htmlspecialchars($currentConfig['cuenta_bancaria']); ?>">
            <input type="hidden" name="banco" value="<?php echo htmlspecialchars($currentConfig['banco']); ?>">
            <input type="hidden" name="mensaje_final" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>">
            <input type="hidden" name="id_empresa" value="<?php echo (int)$currentConfig['id_empresa']; ?>">
            <input type="hidden" name="id_sucursal" value="<?php echo (int)$currentConfig['id_sucursal']; ?>">
            <input type="hidden" name="id_almacen" value="<?php echo (int)$currentConfig['id_almacen']; ?>">
            <input type="hidden" name="mensajeria_tarifa_km" value="<?php echo htmlspecialchars((string)$currentConfig['mensajeria_tarifa_km']); ?>">
            <input type="hidden" name="semana_inicio_dia" value="<?php echo (int)$currentConfig['semana_inicio_dia']; ?>">
            <input type="hidden" name="reserva_limpieza_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_limpieza_pct']); ?>">
            <input type="hidden" name="salario_elaborador_pct" value="<?php echo htmlspecialchars((string)$currentConfig['salario_elaborador_pct']); ?>">
            <input type="hidden" name="reserva_negocio_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_negocio_pct']); ?>">
            <input type="hidden" name="depreciacion_equipos_pct" value="<?php echo htmlspecialchars((string)$currentConfig['depreciacion_equipos_pct']); ?>">
            <?php if (!empty($currentConfig['mostrar_materias_primas'])): ?><input type="hidden" name="mostrar_materias_primas" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['mostrar_servicios'])): ?><input type="hidden" name="mostrar_servicios" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['kiosco_solo_stock'])): ?><input type="hidden" name="kiosco_solo_stock" value="1"><?php endif; ?>
            <input type="hidden" name="customer_display_chime_type" value="<?php echo htmlspecialchars($currentConfig['customer_display_chime_type']); ?>">
            <input type="hidden" name="customer_display_insect" value="<?php echo htmlspecialchars($currentConfig['customer_display_insect']); ?>">
            <input type="hidden" name="numero_tarjeta" value="<?php echo htmlspecialchars($currentConfig['numero_tarjeta']); ?>">
            <input type="hidden" name="titular_tarjeta" value="<?php echo htmlspecialchars($currentConfig['titular_tarjeta']); ?>">
            <input type="hidden" name="banco_tarjeta" value="<?php echo htmlspecialchars($currentConfig['banco_tarjeta']); ?>">
            <input type="hidden" name="facebook_url" value="<?php echo htmlspecialchars($currentConfig['facebook_url']); ?>">
            <input type="hidden" name="twitter_url" value="<?php echo htmlspecialchars($currentConfig['twitter_url']); ?>">
            <input type="hidden" name="instagram_url" value="<?php echo htmlspecialchars($currentConfig['instagram_url']); ?>">
            <input type="hidden" name="youtube_url" value="<?php echo htmlspecialchars($currentConfig['youtube_url']); ?>">
            <input type="hidden" name="tipo_cambio_usd" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_usd']); ?>">
            <input type="hidden" name="tipo_cambio_mlc" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_mlc']); ?>">
            <input type="hidden" name="moneda_default_pos" value="<?php echo htmlspecialchars($currentConfig['moneda_default_pos']); ?>">
            <input type="hidden" name="vapid_public_key" value="<?php echo htmlspecialchars($currentConfig['vapid_public_key'] ?? ''); ?>">
            <?php foreach (($currentConfig['categorias_ocultas'] ?? []) as $hiddenCategory): ?><input type="hidden" name="categorias_ocultas[]" value="<?php echo htmlspecialchars($hiddenCategory); ?>"><?php endforeach; ?>
            <?php foreach (($currentConfig['notification_type_settings'] ?? []) as $key => $enabled): ?><input type="hidden" name="notification_type_keys[]" value="<?php echo htmlspecialchars($key); ?>"><?php if ($enabled): ?><input type="hidden" name="notification_type_enabled[]" value="<?php echo htmlspecialchars($key); ?>"><?php endif; ?><?php endforeach; ?>
            <?php foreach (($currentConfig['metodos_pago'] ?? []) as $idx => $metodo): ?>
                <input type="hidden" name="metodo_id[]" value="<?php echo htmlspecialchars($metodo['id'] ?? ''); ?>">
                <input type="hidden" name="metodo_nombre[]" value="<?php echo htmlspecialchars($metodo['nombre'] ?? ''); ?>">
                <input type="hidden" name="metodo_icono[]" value="<?php echo htmlspecialchars($metodo['icono'] ?? ''); ?>">
                <input type="hidden" name="metodo_color[]" value="<?php echo htmlspecialchars($metodo['color_bootstrap'] ?? ''); ?>">
                <input type="hidden" name="metodo_texto_especial[]" value="<?php echo htmlspecialchars($metodo['texto_especial'] ?? ''); ?>">
                <?php if (!empty($metodo['activo'])): ?><input type="hidden" name="metodo_activo[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['requiere_codigo'])): ?><input type="hidden" name="metodo_requiere_codigo[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['aplica_pos'])): ?><input type="hidden" name="metodo_aplica_pos[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['aplica_shop'])): ?><input type="hidden" name="metodo_aplica_shop[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['es_transferencia'])): ?><input type="hidden" name="metodo_es_transferencia[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['es_especial'])): ?><input type="hidden" name="metodo_es_especial[]" value="<?php echo $idx; ?>"><?php endif; ?>
            <?php endforeach; ?>

            <?php
            $ticketLogoPath = trim((string)($currentConfig['ticket_logo'] ?? ''));
            $ticketLogoExists = $ticketLogoPath !== '' && file_exists(__DIR__ . '/' . $ticketLogoPath);
            $ticketToggles = [
                ['ticket_mostrar_cajero', 'chkMostrarCajero', 'fas fa-user-tie', 'Cajero', !empty($currentConfig['ticket_mostrar_cajero'])],
                ['ticket_mostrar_canal', 'chkMostrarCanal', 'fas fa-tag', 'Canal de origen', !empty($currentConfig['ticket_mostrar_canal'])],
                ['ticket_mostrar_uuid', 'chkMostrarUuid', 'fas fa-fingerprint', 'UUID de venta', !empty($currentConfig['ticket_mostrar_uuid'])],
                ['ticket_mostrar_items_count', 'chkMostrarItemsCount', 'fas fa-list-ol', 'Total de ítems', !empty($currentConfig['ticket_mostrar_items_count'])],
                ['ticket_mostrar_qr', 'chkMostrarQr', 'fas fa-qrcode', 'Código QR', !empty($currentConfig['ticket_mostrar_qr'])],
            ];
            ?>
            <div class="card">
                <div class="card-header fw-bold text-primary">🧾 Diseño del ticket</div>
                <div class="card-body">
                    <div class="row g-4">
                        <div class="col-xl-7">
                            <div class="mb-4 pb-3 border-bottom">
                                <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-image me-1"></i>Logo</h6>
                                <?php if ($ticketLogoExists): ?>
                                    <div class="d-flex align-items-center gap-3 mb-2 p-2 border rounded bg-light">
                                        <img src="<?php echo htmlspecialchars($ticketLogoPath); ?>?v=<?php echo filemtime(__DIR__ . '/' . $ticketLogoPath); ?>" style="max-height:70px; max-width:180px; border-radius:4px;" alt="Logo actual">
                                        <div>
                                            <span class="badge bg-success mb-2"><i class="fas fa-check me-1"></i>Logo activo</span>
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" name="ticket_logo_remove" id="ticket_logo_remove" value="1" onchange="updateTicketPreview()">
                                                <label class="form-check-label small text-danger fw-semibold" for="ticket_logo_remove">Eliminar logo actual</label>
                                            </div>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <div class="mb-2 p-2 border rounded bg-light text-muted small">
                                        <i class="fas fa-ban me-1"></i> Sin logo. Se mostrará solo el nombre del negocio.
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="ticket_logo" class="form-control" accept="image/jpeg,image/png,image/webp" onchange="onLogoFileChange(this)">
                                <div class="form-text">JPG, PNG o WebP. Máximo 2MB. Recomendado: 280 × 80 px.</div>
                            </div>

                            <div class="mb-4 pb-3 border-bottom">
                                <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-font me-1"></i>Textos del ticket</h6>
                                <div class="mb-3">
                                    <label class="form-label">Slogan / tagline</label>
                                    <input type="text" name="ticket_slogan" class="form-control" value="<?php echo htmlspecialchars($currentConfig['ticket_slogan']); ?>" oninput="updateTicketPreview()">
                                </div>
                                <div>
                                    <label class="form-label">Mensaje final</label>
                                    <input type="text" name="mensaje_final" class="form-control" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>" oninput="updateTicketPreview()">
                                </div>
                            </div>

                            <div>
                                <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-sliders-h me-1"></i>Elementos a mostrar</h6>
                                <div class="row g-2">
                                    <?php foreach ($ticketToggles as [$name, $id, $icon, $label, $checked]): ?>
                                        <div class="col-sm-6">
                                            <div class="form-check form-switch p-2 rounded border bg-light">
                                                <input class="form-check-input" type="checkbox" role="switch" name="<?php echo $name; ?>" id="<?php echo $id; ?>" <?php echo $checked ? 'checked' : ''; ?> onchange="updateTicketPreview()">
                                                <label class="form-check-label small fw-semibold" for="<?php echo $id; ?>">
                                                    <i class="<?php echo $icon; ?> me-1 text-secondary"></i><?php echo $label; ?>
                                                </label>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-5">
                            <div class="sticky-top" style="top:20px;">
                                <h6 class="fw-bold text-secondary mb-2"><i class="fas fa-eye me-1"></i>Vista Previa del Ticket</h6>
                                <div style="background:#d1d5db; padding:16px; border-radius:10px;">
                                    <div id="ticketPreview" style="font-family:'Courier New',monospace; font-size:11px; background:#fff; border:1px solid #999; padding:12px; width:220px; margin:0 auto; box-shadow:2px 2px 0 #bbb;"></div>
                                </div>
                                <div class="form-text mt-2 text-center">
                                    Vista aproximada ·
                                    <a href="ticket_view.php?id=216" target="_blank" class="text-decoration-none">
                                        Ver ticket real <i class="fas fa-external-link-alt"></i>
                                    </a>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100 shadow">Guardar ticket</button>
        </form>
    </div>

    <div class="cfg-tab d-none" data-tab-panel="pantalla">
        <form method="post">
            <input type="hidden" name="form_action" value="save_shop_config">
            <input type="hidden" name="marca_sistema_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_sistema_nombre'] ?? 'PalWeb POS Marinero'); ?>">
            <input type="hidden" name="marca_empresa_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_empresa_nombre'] ?? ($currentConfig['tienda_nombre'] ?? 'MI TIENDA')); ?>">
            <input type="hidden" name="tienda_nombre" value="<?php echo htmlspecialchars($currentConfig['tienda_nombre']); ?>">
            <input type="hidden" name="direccion" value="<?php echo htmlspecialchars($currentConfig['direccion']); ?>">
            <input type="hidden" name="telefono" value="<?php echo htmlspecialchars($currentConfig['telefono']); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($currentConfig['email']); ?>">
            <input type="hidden" name="website" value="<?php echo htmlspecialchars($currentConfig['website']); ?>">
            <input type="hidden" name="nit" value="<?php echo htmlspecialchars($currentConfig['nit']); ?>">
            <input type="hidden" name="cuenta_bancaria" value="<?php echo htmlspecialchars($currentConfig['cuenta_bancaria']); ?>">
            <input type="hidden" name="banco" value="<?php echo htmlspecialchars($currentConfig['banco']); ?>">
            <input type="hidden" name="mensaje_final" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>">
            <input type="hidden" name="id_empresa" value="<?php echo (int)$currentConfig['id_empresa']; ?>">
            <input type="hidden" name="id_sucursal" value="<?php echo (int)$currentConfig['id_sucursal']; ?>">
            <input type="hidden" name="id_almacen" value="<?php echo (int)$currentConfig['id_almacen']; ?>">
            <input type="hidden" name="mensajeria_tarifa_km" value="<?php echo htmlspecialchars((string)$currentConfig['mensajeria_tarifa_km']); ?>">
            <input type="hidden" name="semana_inicio_dia" value="<?php echo (int)$currentConfig['semana_inicio_dia']; ?>">
            <input type="hidden" name="reserva_limpieza_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_limpieza_pct']); ?>">
            <input type="hidden" name="salario_elaborador_pct" value="<?php echo htmlspecialchars((string)$currentConfig['salario_elaborador_pct']); ?>">
            <input type="hidden" name="reserva_negocio_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_negocio_pct']); ?>">
            <input type="hidden" name="depreciacion_equipos_pct" value="<?php echo htmlspecialchars((string)$currentConfig['depreciacion_equipos_pct']); ?>">
            <?php if (!empty($currentConfig['mostrar_materias_primas'])): ?><input type="hidden" name="mostrar_materias_primas" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['mostrar_servicios'])): ?><input type="hidden" name="mostrar_servicios" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['kiosco_solo_stock'])): ?><input type="hidden" name="kiosco_solo_stock" value="1"><?php endif; ?>
            <input type="hidden" name="numero_tarjeta" value="<?php echo htmlspecialchars($currentConfig['numero_tarjeta']); ?>">
            <input type="hidden" name="titular_tarjeta" value="<?php echo htmlspecialchars($currentConfig['titular_tarjeta']); ?>">
            <input type="hidden" name="banco_tarjeta" value="<?php echo htmlspecialchars($currentConfig['banco_tarjeta']); ?>">
            <input type="hidden" name="facebook_url" value="<?php echo htmlspecialchars($currentConfig['facebook_url']); ?>">
            <input type="hidden" name="twitter_url" value="<?php echo htmlspecialchars($currentConfig['twitter_url']); ?>">
            <input type="hidden" name="instagram_url" value="<?php echo htmlspecialchars($currentConfig['instagram_url']); ?>">
            <input type="hidden" name="youtube_url" value="<?php echo htmlspecialchars($currentConfig['youtube_url']); ?>">
            <input type="hidden" name="ticket_logo" value="<?php echo htmlspecialchars($currentConfig['ticket_logo']); ?>">
            <input type="hidden" name="ticket_slogan" value="<?php echo htmlspecialchars($currentConfig['ticket_slogan']); ?>">
            <?php if (!empty($currentConfig['ticket_mostrar_uuid'])): ?><input type="hidden" name="ticket_mostrar_uuid" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['ticket_mostrar_canal'])): ?><input type="hidden" name="ticket_mostrar_canal" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['ticket_mostrar_cajero'])): ?><input type="hidden" name="ticket_mostrar_cajero" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['ticket_mostrar_qr'])): ?><input type="hidden" name="ticket_mostrar_qr" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['ticket_mostrar_items_count'])): ?><input type="hidden" name="ticket_mostrar_items_count" value="1"><?php endif; ?>
            <input type="hidden" name="tipo_cambio_usd" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_usd']); ?>">
            <input type="hidden" name="tipo_cambio_mlc" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_mlc']); ?>">
            <input type="hidden" name="moneda_default_pos" value="<?php echo htmlspecialchars($currentConfig['moneda_default_pos']); ?>">
            <input type="hidden" name="vapid_public_key" value="<?php echo htmlspecialchars($currentConfig['vapid_public_key'] ?? ''); ?>">
            <?php foreach (($currentConfig['categorias_ocultas'] ?? []) as $hiddenCategory): ?><input type="hidden" name="categorias_ocultas[]" value="<?php echo htmlspecialchars($hiddenCategory); ?>"><?php endforeach; ?>
            <?php foreach (($currentConfig['notification_type_settings'] ?? []) as $key => $enabled): ?><input type="hidden" name="notification_type_keys[]" value="<?php echo htmlspecialchars($key); ?>"><?php if ($enabled): ?><input type="hidden" name="notification_type_enabled[]" value="<?php echo htmlspecialchars($key); ?>"><?php endif; ?><?php endforeach; ?>
            <?php foreach (($currentConfig['metodos_pago'] ?? []) as $idx => $metodo): ?>
                <input type="hidden" name="metodo_id[]" value="<?php echo htmlspecialchars($metodo['id'] ?? ''); ?>">
                <input type="hidden" name="metodo_nombre[]" value="<?php echo htmlspecialchars($metodo['nombre'] ?? ''); ?>">
                <input type="hidden" name="metodo_icono[]" value="<?php echo htmlspecialchars($metodo['icono'] ?? ''); ?>">
                <input type="hidden" name="metodo_color[]" value="<?php echo htmlspecialchars($metodo['color_bootstrap'] ?? ''); ?>">
                <input type="hidden" name="metodo_texto_especial[]" value="<?php echo htmlspecialchars($metodo['texto_especial'] ?? ''); ?>">
                <?php if (!empty($metodo['activo'])): ?><input type="hidden" name="metodo_activo[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['requiere_codigo'])): ?><input type="hidden" name="metodo_requiere_codigo[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['aplica_pos'])): ?><input type="hidden" name="metodo_aplica_pos[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['aplica_shop'])): ?><input type="hidden" name="metodo_aplica_shop[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['es_transferencia'])): ?><input type="hidden" name="metodo_es_transferencia[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['es_especial'])): ?><input type="hidden" name="metodo_es_especial[]" value="<?php echo $idx; ?>"><?php endif; ?>
            <?php endforeach; ?>

            <div class="card">
                <div class="card-header fw-bold text-info">🖥️ Pantalla del cliente</div>
                <div class="card-body row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Sonido / chime</label>
                        <select class="form-select" name="customer_display_chime_type">
                            <?php foreach ([
                                'mixkit_bell' => 'Campana Mixkit',
                                'ding' => 'Ding corto',
                                'cuckoo' => 'Pájaro Cu-cú',
                                'church' => 'Campana de iglesia',
                            ] as $chime => $chimeLabel): ?>
                                <option value="<?php echo $chime; ?>" <?php echo ($currentConfig['customer_display_chime_type'] ?? '') === $chime ? 'selected' : ''; ?>><?php echo htmlspecialchars($chimeLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12">
                        <label class="form-label d-block">Insecto / animación</label>
                        <div class="d-flex flex-wrap gap-3">
                            <?php foreach (['mosca' => '🪰 Mosca', 'mariposa' => '🦋 Mariposa', 'mariquita' => '🐞 Mariquita'] as $insectValue => $insectLabel): ?>
                                <label class="insect-option">
                                    <input type="radio" class="d-none" name="customer_display_insect" value="<?php echo $insectValue; ?>" <?php echo ($currentConfig['customer_display_insect'] ?? 'mosca') === $insectValue ? 'checked' : ''; ?>>
                                    <div class="insect-card"><?php echo $insectLabel; ?></div>
                                </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="card">
                <div class="card-header fw-bold text-primary">📱 Kiosco</div>
                <div class="card-body row g-3">
                    <div class="col-md-12 form-check ms-2"><input class="form-check-input" type="checkbox" id="pantalla_kiosco_solo_stock" name="kiosco_solo_stock" <?php echo !empty($currentConfig['kiosco_solo_stock']) ? 'checked' : ''; ?>><label class="form-check-label" for="pantalla_kiosco_solo_stock">Kiosco solo muestra productos con stock</label></div>
                </div>
            </div>
            <button type="submit" class="btn btn-info text-white btn-lg w-100 shadow">Guardar pantalla</button>
        </form>
    </div>

    <div class="cfg-tab d-none" data-tab-panel="estructura">
        <div class="row g-4">
            <div class="col-xl-4">
                <div class="card">
                    <div class="card-header fw-bold text-primary">🏢 Empresa</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="form_action" value="save_empresa">
                            <input type="hidden" name="empresa_id" value="<?php echo (int)$editEmpresaData['id']; ?>">
                            <div class="col-12">
                                <label class="form-label">Nombre de empresa</label>
                                <input type="text" class="form-control" name="empresa_nombre" required value="<?php echo htmlspecialchars($editEmpresaData['nombre']); ?>">
                            </div>
                            <div class="col-12 form-check ms-2">
                                <input class="form-check-input" type="checkbox" id="empresa_activo" name="empresa_activo" <?php echo !empty($editEmpresaData['activo']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="empresa_activo">Empresa activa</label>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-primary"><?php echo $editEmpresaData['id'] > 0 ? 'Actualizar empresa' : 'Crear empresa'; ?></button>
                                <?php if ($editEmpresaData['id'] > 0): ?><a href="pos_config2.php" class="btn btn-outline-secondary">Cancelar</a><?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-bold text-success">🏬 Sucursal</div>
                    <div class="card-body">
                        <form method="post" enctype="multipart/form-data" class="row g-3">
                            <input type="hidden" name="form_action" value="save_sucursal">
                            <input type="hidden" name="sucursal_id" value="<?php echo (int)$editSucursalData['id']; ?>">
                            <div class="col-12">
                                <label class="form-label">Empresa</label>
                                <select class="form-select" name="sucursal_id_empresa" required>
                                    <option value="">Selecciona empresa</option>
                                    <?php foreach ($allEmpresas as $empresa): ?>
                                        <option value="<?php echo (int)$empresa['id']; ?>" <?php echo (string)$editSucursalData['id_empresa'] === (string)$empresa['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($empresa['nombre']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nombre de sucursal</label>
                                <input type="text" class="form-control" name="sucursal_nombre" required value="<?php echo htmlspecialchars($editSucursalData['nombre']); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Banner Hero (POS)</label>
                                <?php if (!empty($editSucursalData['imagen_banner'])): ?>
                                    <div class="mb-2">
                                        <img src="<?php echo htmlspecialchars($editSucursalData['imagen_banner']); ?>" class="img-thumbnail" style="max-height: 80px;">
                                        <div class="form-check small mt-1">
                                            <input class="form-check-input" type="checkbox" name="sucursal_banner_remove" id="sucursal_banner_remove" value="1">
                                            <label class="form-check-label text-danger" for="sucursal_banner_remove">Eliminar banner</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                <input type="file" name="sucursal_banner" class="form-control form-control-sm" accept="image/*">
                                <div class="form-text small">Imagen panorámica para el fondo del banner superior en el POS.</div>
                            </div>
                            <div class="col-12 form-check ms-2">                                <input class="form-check-input" type="checkbox" id="sucursal_activo" name="sucursal_activo" <?php echo !empty($editSucursalData['activo']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="sucursal_activo">Sucursal activa</label>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-success"><?php echo $editSucursalData['id'] > 0 ? 'Actualizar sucursal' : 'Crear sucursal'; ?></button>
                                <?php if ($editSucursalData['id'] > 0): ?><a href="pos_config2.php" class="btn btn-outline-secondary">Cancelar</a><?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>

                <div class="card">
                    <div class="card-header fw-bold text-warning">📦 Almacén</div>
                    <div class="card-body">
                        <form method="post" class="row g-3">
                            <input type="hidden" name="form_action" value="save_almacen">
                            <input type="hidden" name="almacen_id" value="<?php echo (int)$editAlmacenData['id']; ?>">
                            <div class="col-12">
                                <label class="form-label">Sucursal</label>
                                <select class="form-select" name="almacen_id_sucursal" required>
                                    <option value="">Selecciona sucursal</option>
                                    <?php foreach ($allSucursales as $sucursal): ?>
                                        <option value="<?php echo (int)$sucursal['id']; ?>" <?php echo (string)$editAlmacenData['id_sucursal'] === (string)$sucursal['id'] ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($sucursal['nombre'] . ' · Empresa ' . $sucursal['id_empresa']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label">Nombre de almacén</label>
                                <input type="text" class="form-control" name="almacen_nombre" required value="<?php echo htmlspecialchars($editAlmacenData['nombre']); ?>">
                            </div>
                            <div class="col-12 form-check ms-2">
                                <input class="form-check-input" type="checkbox" id="almacen_activo" name="almacen_activo" <?php echo !empty($editAlmacenData['activo']) ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="almacen_activo">Almacén activo</label>
                            </div>
                            <div class="col-12 d-flex gap-2">
                                <button type="submit" class="btn btn-warning"><?php echo $editAlmacenData['id'] > 0 ? 'Actualizar almacén' : 'Crear almacén'; ?></button>
                                <?php if ($editAlmacenData['id'] > 0): ?><a href="pos_config2.php" class="btn btn-outline-secondary">Cancelar</a><?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-8">
                <div class="card">
                    <div class="card-header fw-bold text-dark">🌳 Árbol de empresa / sucursal / almacén</div>
                    <div class="card-body">
                        <div class="section-note mb-3">La estructura se administra desde arriba hacia abajo. Cada empresa contiene sus sucursales y cada sucursal contiene sus almacenes.</div>
                        <?php foreach ($treeCompanies as $empresaId => $treeCompany): $empresa = $treeCompany['empresa']; ?>
                            <div class="tree-node">
                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                                    <div>
                                        <div class="tree-pill mb-2"><i class="fas fa-building"></i> Empresa</div>
                                        <div class="fs-5 fw-bold"><?php echo htmlspecialchars($empresa['nombre']); ?></div>
                                        <div class="small-muted">ID <?php echo (int)$empresa['id']; ?> · <?php echo !empty($empresa['activo']) ? 'Activa' : 'Inactiva'; ?></div>
                                    </div>
                                    <div class="d-flex gap-2 flex-wrap align-self-start">
                                        <a href="pos_config2.php?edit_empresa=<?php echo (int)$empresa['id']; ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="form_action" value="delete_empresa">
                                            <input type="hidden" name="empresa_id" value="<?php echo (int)$empresa['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Eliminar esta empresa?')">Eliminar</button>
                                        </form>
                                    </div>
                                </div>

                                <div class="tree-branch">
                                    <?php if (!empty($treeCompany['sucursales'])): ?>
                                        <?php foreach ($treeCompany['sucursales'] as $sucursalId => $treeSucursal): $sucursal = $treeSucursal['sucursal']; ?>
                                            <div class="tree-node mb-3">
                                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                                                    <div>
                                                        <div class="tree-pill mb-2" style="background:#ecfdf5;color:#047857;border-color:#bbf7d0;"><i class="fas fa-store"></i> Sucursal</div>
                                                        <div class="mb-2">
                                                            <?php 
                                                            ?>
                                                            <?php if (!empty($sucursal['imagen_banner']) && file_exists(__DIR__ . '/' . $sucursal['imagen_banner'])): ?>
                                                                <img src="<?php echo htmlspecialchars($sucursal['imagen_banner']); ?>?v=<?php echo time(); ?>"
                                                                     style="height:45px; border-radius:6px; box-shadow:0 2px 4px rgba(0,0,0,0.1); background:#fff;" alt="Banner">
                                                            <?php else: ?>
                                                                <div style="height:45px;width:130px;border-radius:6px;background:#f1f5f9;border:1px dashed #cbd5e1;display:flex;align-items:center;justify-content:center;color:#94a3b8;font-size:0.72rem;">
                                                                    <i class="fas fa-image me-1"></i> Sin banner
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                        <div class="fw-bold"><?php echo htmlspecialchars($sucursal['nombre']); ?></div>
                                                        <div class="small-muted">ID <?php echo (int)$sucursal['id']; ?> · <?php echo !empty($sucursal['activo']) ? 'Activa' : 'Inactiva'; ?></div>
                                                    </div>
                                                    <div class="d-flex gap-2 flex-wrap align-self-start">
                                                        <a href="pos_config2.php?edit_sucursal=<?php echo (int)$sucursal['id']; ?>" class="btn btn-sm btn-outline-success">Editar</a>
                                                        <form method="post" class="d-inline">
                                                            <input type="hidden" name="form_action" value="delete_sucursal">
                                                            <input type="hidden" name="sucursal_id" value="<?php echo (int)$sucursal['id']; ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Eliminar esta sucursal?')">Eliminar</button>
                                                        </form>
                                                    </div>
                                                </div>

                                                <div class="tree-branch">
                                                    <?php if (!empty($treeSucursal['almacenes'])): ?>
                                                        <?php foreach ($treeSucursal['almacenes'] as $almacen): ?>
                                                            <div class="tree-node mb-2">
                                                                <div class="d-flex flex-column flex-lg-row justify-content-between gap-3">
                                                                    <div>
                                                                        <div class="tree-pill mb-2" style="background:#fff7ed;color:#b45309;border-color:#fed7aa;"><i class="fas fa-warehouse"></i> Almacén</div>
                                                                        <div class="fw-bold"><?php echo htmlspecialchars($almacen['nombre']); ?></div>
                                                                        <div class="small-muted">ID <?php echo (int)$almacen['id']; ?> · <?php echo !empty($almacen['activo']) ? 'Activo' : 'Inactivo'; ?></div>
                                                                    </div>
                                                                    <div class="d-flex gap-2 flex-wrap align-self-start">
                                                                        <a href="pos_config2.php?edit_almacen=<?php echo (int)$almacen['id']; ?>" class="btn btn-sm btn-outline-warning">Editar</a>
                                                                        <form method="post" class="d-inline">
                                                                            <input type="hidden" name="form_action" value="delete_almacen">
                                                                            <input type="hidden" name="almacen_id" value="<?php echo (int)$almacen['id']; ?>">
                                                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Eliminar este almacén?')">Eliminar</button>
                                                                        </form>
                                                                    </div>
                                                                </div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    <?php else: ?>
                                                        <div class="small-muted">Sin almacenes en esta sucursal.</div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="small-muted">Sin sucursales en esta empresa.</div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="cfg-tab d-none" data-tab-panel="usuarios">
        <div class="row g-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header fw-bold text-success">👤 Usuario ERP y contexto operativo</div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">Cada usuario del sistema puede quedar ligado a una empresa, sucursal y almacén. Ese contexto se aplica por login en los módulos dinámicos.</p>
                        <form method="post" class="row g-3">
                    <input type="hidden" name="form_action" value="save_user">
                    <input type="hidden" name="user_id" value="<?php echo (int)$editUserData['id']; ?>">
                    <div class="col-md-3">
                        <label class="form-label">Nombre</label>
                        <input type="text" class="form-control" name="user_nombre" required value="<?php echo htmlspecialchars($editUserData['nombre']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Email</label>
                        <input type="email" class="form-control" name="user_email" value="<?php echo htmlspecialchars($editUserData['email']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">PIN</label>
                        <input type="text" class="form-control mono" name="user_pin" required value="<?php echo htmlspecialchars($editUserData['pin']); ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label"><?php echo $editUserData['id'] > 0 ? 'Nueva contraseña' : 'Contraseña'; ?></label>
                        <input type="password" class="form-control" name="user_password" autocomplete="<?php echo $editUserData['id'] > 0 ? 'new-password' : 'current-password'; ?>" <?php echo $editUserData['id'] > 0 ? '' : 'required'; ?> placeholder="<?php echo $editUserData['id'] > 0 ? 'Dejar vacía para mantener' : ''; ?>">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Rol</label>
                        <select class="form-select" name="user_rol">
                            <?php foreach (['admin','cajero','supervisor','operador'] as $role): ?>
                                <option value="<?php echo $role; ?>" <?php echo $editUserData['rol'] === $role ? 'selected' : ''; ?>><?php echo $role; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Empresa</label>
                        <select class="form-select" id="userEmpresaSelect" name="user_id_empresa" required>
                            <option value="">Empresa</option>
                            <?php foreach ($empresas as $empresa): ?>
                                <option value="<?php echo (int)$empresa['id']; ?>" <?php echo (string)$editUserData['id_empresa'] === (string)$empresa['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($empresa['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Sucursal</label>
                        <select class="form-select" id="userSucursalSelect" name="user_id_sucursal" required>
                            <option value="">Sucursal</option>
                            <?php foreach ($sucursales as $sucursal): ?>
                                <option value="<?php echo (int)$sucursal['id']; ?>" data-empresa-id="<?php echo (int)$sucursal['id_empresa']; ?>" <?php echo (string)$editUserData['id_sucursal'] === (string)$sucursal['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($sucursal['nombre'] . ' · Emp ' . $sucursal['id_empresa']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Almacén</label>
                        <select class="form-select" id="userAlmacenSelect" name="user_id_almacen" required>
                            <option value="">Almacén</option>
                            <?php foreach ($almacenes as $almacen): ?>
                                <option value="<?php echo (int)$almacen['id']; ?>" data-sucursal-id="<?php echo (int)$almacen['id_sucursal']; ?>" <?php echo (string)$editUserData['id_almacen'] === (string)$almacen['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($almacen['nombre'] . ' · Suc ' . $almacen['id_sucursal']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 form-check ms-2">
                        <input class="form-check-input" type="checkbox" id="user_activo" name="user_activo" <?php echo !empty($editUserData['activo']) ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="user_activo">Asignación activa</label>
                    </div>
                            <div class="col-md-12">
                                <button type="submit" class="btn btn-success"><?php echo $editUserData['id'] > 0 ? 'Actualizar usuario' : 'Crear usuario'; ?></button>
                                <?php if ($editUserData['id'] > 0): ?>
                                    <a href="pos_config2.php" class="btn btn-outline-secondary">Cancelar edición</a>
                                <?php endif; ?>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header fw-bold text-secondary">📊 Resumen de usuarios</div>
                    <div class="card-body">
                        <?php
                        $userTotal = count($userContexts);
                        $userActive = 0;
                        $userNoAssign = 0;
                        $userLoginOff = 0;
                        foreach ($userContexts as $row) {
                            if (!empty($row['context_activo'])) { $userActive++; }
                            if (empty($row['id_empresa'])) { $userNoAssign++; }
                            if (empty($row['user_login_activo'])) { $userLoginOff++; }
                        }
                        ?>
                        <div class="row g-3">
                            <div class="col-6"><div class="border rounded-3 p-3 bg-light h-100"><div class="small text-muted">Total</div><div class="fs-4 fw-bold"><?php echo (int)$userTotal; ?></div></div></div>
                            <div class="col-6"><div class="border rounded-3 p-3 bg-light h-100"><div class="small text-muted">Activos</div><div class="fs-4 fw-bold text-success"><?php echo (int)$userActive; ?></div></div></div>
                            <div class="col-6"><div class="border rounded-3 p-3 bg-light h-100"><div class="small text-muted">Sin asignación</div><div class="fs-4 fw-bold text-danger"><?php echo (int)$userNoAssign; ?></div></div></div>
                            <div class="col-6"><div class="border rounded-3 p-3 bg-light h-100"><div class="small text-muted">Login off</div><div class="fs-4 fw-bold text-dark"><?php echo (int)$userLoginOff; ?></div></div></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-8">
                <div class="card h-100">
                    <div class="card-header fw-bold text-secondary">🔎 Filtros de usuarios</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-5">
                                <input type="text" class="form-control" id="userTableSearch" placeholder="Buscar por nombre, email, PIN o contexto">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="userRoleFilter">
                                    <option value="">Todos los roles</option>
                                    <option value="admin">admin</option>
                                    <option value="cajero">cajero</option>
                                    <option value="supervisor">supervisor</option>
                                    <option value="operador">operador</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <select class="form-select" id="userStatusFilter">
                                    <option value="">Todos los estados</option>
                                    <option value="activa">Asignación activa</option>
                                    <option value="inactiva">Asignación inactiva</option>
                                    <option value="sin_asignacion">Sin asignación</option>
                                    <option value="login_off">Login desactivado</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header fw-bold text-secondary">📋 Usuarios ERP configurados</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle" id="usersCrudTable">
                        <thead class="table-light">
                            <tr>
                                <th>Usuario</th>
                                <th>Contacto</th>
                                <th>Rol</th>
                                <th>Contexto</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($userContexts as $row): ?>
                                <tr
                                    data-user-row="1"
                                    data-search="<?php echo htmlspecialchars(mb_strtolower(trim(($row['user_nombre'] ?? '') . ' ' . ($row['user_email'] ?? '') . ' ' . ($row['user_pin'] ?? '') . ' ' . ($row['empresa_nombre'] ?? '') . ' ' . ($row['sucursal_nombre'] ?? '') . ' ' . ($row['almacen_nombre'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-role="<?php echo htmlspecialchars($row['user_rol'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-state="<?php echo empty($row['id_empresa']) ? 'sin_asignacion' : (!empty($row['context_activo']) ? 'activa' : 'inactiva'); ?>"
                                    data-login="<?php echo empty($row['user_login_activo']) ? 'login_off' : 'login_on'; ?>"
                                >
                                    <td>
                                        <div class="fw-semibold"><?php echo htmlspecialchars($row['user_nombre']); ?></div>
                                        <div class="small-muted mono">PIN <?php echo htmlspecialchars($row['user_pin'] ?? ''); ?></div>
                                    </td>
                                    <td><?php echo htmlspecialchars($row['user_email'] ?: '—'); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($row['user_rol']); ?></span></td>
                                    <td>
                                        <?php if (!empty($row['id_empresa'])): ?>
                                            <div><?php echo htmlspecialchars(($row['empresa_nombre'] ?? 'Emp ' . $row['id_empresa']) . ' / ' . ($row['sucursal_nombre'] ?? 'Suc ' . $row['id_sucursal']) . ' / ' . ($row['almacen_nombre'] ?? 'Alm ' . $row['id_almacen'])); ?></div>
                                            <div class="small-muted mono">E<?php echo (int)$row['id_empresa']; ?> · S<?php echo (int)$row['id_sucursal']; ?> · A<?php echo (int)$row['id_almacen']; ?></div>
                                        <?php else: ?>
                                            <span class="text-danger fw-semibold">Sin asignación</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['context_activo'])): ?><span class="badge bg-success">Activa</span><?php else: ?><span class="badge bg-danger">Inactiva</span><?php endif; ?>
                                        <?php if (empty($row['user_login_activo'])): ?><span class="badge bg-dark">Login off</span><?php endif; ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="pos_config2.php?edit_user=<?php echo (int)$row['user_id']; ?>" class="btn btn-sm btn-outline-primary">Editar</a>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="form_action" value="delete_user">
                                            <input type="hidden" name="user_id" value="<?php echo (int)$row['user_id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" onclick="return confirm('Eliminar usuario del sistema y su contexto POS?')">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="cfg-tab d-none" data-tab-panel="cajeros">
        <div class="row g-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header fw-bold text-info">🧑‍💼 Cajero POS y contexto operativo</div>
                    <div class="card-body">
                        <p class="small text-muted mb-3">Los cajeros del POS trabajan con PIN y contexto propio, sin depender del login del ERP.</p>
                        <form method="post" class="row g-3">
                    <input type="hidden" name="form_action" value="save_cashier">
                    <input type="hidden" name="cashier_id" value="">
                    <div class="col-md-3"><label class="form-label">Nombre</label><input type="text" class="form-control" name="cashier_nombre" required></div>
                    <div class="col-md-2"><label class="form-label">PIN</label><input type="text" class="form-control mono" name="cashier_pin" required></div>
                    <div class="col-md-2">
                        <label class="form-label">Rol</label>
                        <select class="form-select" name="cashier_rol">
                            <option value="cajero">cajero</option>
                            <option value="admin">admin</option>
                            <option value="supervisor">supervisor</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Empresa</label>
                        <select class="form-select" id="cashierEmpresaSelect" name="cashier_id_empresa" required>
                            <option value="">Empresa</option>
                            <?php foreach ($empresas as $empresa): ?>
                                <option value="<?php echo (int)$empresa['id']; ?>"><?php echo htmlspecialchars($empresa['nombre']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Sucursal</label>
                        <select class="form-select" id="cashierSucursalSelect" name="cashier_id_sucursal" required>
                            <option value="">Suc</option>
                            <?php foreach ($sucursales as $sucursal): ?>
                                <option value="<?php echo (int)$sucursal['id']; ?>" data-empresa-id="<?php echo (int)$sucursal['id_empresa']; ?>"><?php echo (int)$sucursal['id']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">Almacén</label>
                        <select class="form-select" id="cashierAlmacenSelect" name="cashier_id_almacen" required>
                            <option value="">Alm</option>
                            <?php foreach ($almacenes as $almacen): ?>
                                <option value="<?php echo (int)$almacen['id']; ?>" data-sucursal-id="<?php echo (int)$almacen['id_sucursal']; ?>"><?php echo (int)$almacen['id']; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 form-check ms-2">
                        <input class="form-check-input" type="checkbox" id="cashier_activo" name="cashier_activo" checked>
                        <label class="form-check-label" for="cashier_activo">Activo</label>
                    </div>
                            <div class="col-md-12"><button type="submit" class="btn btn-info text-white">Guardar cajero</button></div>
                        </form>
                    </div>
                </div>
            </div>

            <div class="col-xl-4">
                <div class="card h-100">
                    <div class="card-header fw-bold text-secondary">📊 Resumen de cajeros</div>
                    <div class="card-body">
                        <?php
                        $cashierTotal = count($cashiers);
                        $cashierActive = 0;
                        $cashierAdmins = 0;
                        foreach ($cashiers as $cashier) {
                            if (!empty($cashier['activo'])) { $cashierActive++; }
                            if (($cashier['rol'] ?? '') === 'admin') { $cashierAdmins++; }
                        }
                        ?>
                        <div class="row g-3">
                            <div class="col-6"><div class="border rounded-3 p-3 bg-light h-100"><div class="small text-muted">Total</div><div class="fs-4 fw-bold"><?php echo (int)$cashierTotal; ?></div></div></div>
                            <div class="col-6"><div class="border rounded-3 p-3 bg-light h-100"><div class="small text-muted">Activos</div><div class="fs-4 fw-bold text-success"><?php echo (int)$cashierActive; ?></div></div></div>
                            <div class="col-6"><div class="border rounded-3 p-3 bg-light h-100"><div class="small text-muted">Admins</div><div class="fs-4 fw-bold text-primary"><?php echo (int)$cashierAdmins; ?></div></div></div>
                            <div class="col-6"><div class="border rounded-3 p-3 bg-light h-100"><div class="small text-muted">Inactivos</div><div class="fs-4 fw-bold text-danger"><?php echo (int)max(0, $cashierTotal - $cashierActive); ?></div></div></div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-xl-8">
                <div class="card h-100">
                    <div class="card-header fw-bold text-secondary">🔎 Filtros de cajeros</div>
                    <div class="card-body">
                        <div class="row g-2">
                            <div class="col-md-6">
                                <input type="text" class="form-control" id="cashierTableSearch" placeholder="Buscar cajero por nombre, PIN o contexto">
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="cashierRoleFilter">
                                    <option value="">Todos los roles</option>
                                    <option value="admin">admin</option>
                                    <option value="cajero">cajero</option>
                                    <option value="supervisor">supervisor</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select" id="cashierStatusFilter">
                                    <option value="">Todos los estados</option>
                                    <option value="activo">Activo</option>
                                    <option value="inactivo">Inactivo</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="col-12">
                <div class="card">
                    <div class="card-header fw-bold text-secondary">📋 Cajeros POS configurados</div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table table-sm table-hover align-middle" id="cashiersCrudTable">
                        <thead class="table-light">
                            <tr>
                                <th>Cajero</th>
                                <th>PIN</th>
                                <th>Rol</th>
                                <th>Empresa</th>
                                <th>Sucursal</th>
                                <th>Almacén</th>
                                <th>Estado</th>
                                <th class="text-end">Acciones</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($cashiers as $cashier): ?>
                                <tr
                                    data-cashier-row="1"
                                    data-search="<?php echo htmlspecialchars(mb_strtolower(trim(($cashier['nombre'] ?? '') . ' ' . ($cashier['pin'] ?? '') . ' ' . ($cashier['empresa_nombre'] ?? '') . ' ' . ($cashier['sucursal_nombre'] ?? '') . ' ' . ($cashier['almacen_nombre'] ?? ''))), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-role="<?php echo htmlspecialchars($cashier['rol'], ENT_QUOTES, 'UTF-8'); ?>"
                                    data-state="<?php echo !empty($cashier['activo']) ? 'activo' : 'inactivo'; ?>"
                                >
                                    <td><?php echo htmlspecialchars($cashier['nombre']); ?></td>
                                    <td class="mono"><?php echo htmlspecialchars($cashier['pin']); ?></td>
                                    <td><span class="badge bg-secondary"><?php echo htmlspecialchars($cashier['rol']); ?></span></td>
                                    <td><?php echo htmlspecialchars($cashier['empresa_nombre'] ?? ('Emp ' . $cashier['id_empresa'])); ?></td>
                                    <td><?php echo htmlspecialchars($cashier['sucursal_nombre'] ?? ('Suc ' . $cashier['id_sucursal'])); ?></td>
                                    <td><?php echo htmlspecialchars($cashier['almacen_nombre'] ?? ('Alm ' . $cashier['id_almacen'])); ?></td>
                                    <td><?php if (!empty($cashier['activo'])): ?><span class="badge bg-success">Activo</span><?php else: ?><span class="badge bg-danger">Inactivo</span><?php endif; ?></td>
                                    <td class="text-end">
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="form_action" value="delete_cashier">
                                            <input type="hidden" name="cashier_id" value="<?php echo (int)$cashier['id']; ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">Eliminar</button>
                                        </form>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="cfg-tab d-none" data-tab-panel="notificaciones">
        <div class="card">
            <div class="card-header fw-bold text-warning">Tipos de notificaciones push</div>
            <div class="card-body">
                <form method="post">
                    <input type="hidden" name="form_action" value="save_shop_config">
                    <input type="hidden" name="marca_sistema_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_sistema_nombre'] ?? 'PalWeb POS Marinero'); ?>">
                    <input type="hidden" name="marca_empresa_nombre" value="<?php echo htmlspecialchars($currentConfig['marca_empresa_nombre'] ?? ($currentConfig['tienda_nombre'] ?? 'MI TIENDA')); ?>">
                    <input type="hidden" name="tienda_nombre" value="<?php echo htmlspecialchars($currentConfig['tienda_nombre']); ?>">
                    <input type="hidden" name="direccion" value="<?php echo htmlspecialchars($currentConfig['direccion']); ?>">
                    <input type="hidden" name="telefono" value="<?php echo htmlspecialchars($currentConfig['telefono']); ?>">
                    <input type="hidden" name="email" value="<?php echo htmlspecialchars($currentConfig['email']); ?>">
                    <input type="hidden" name="website" value="<?php echo htmlspecialchars($currentConfig['website']); ?>">
                    <input type="hidden" name="nit" value="<?php echo htmlspecialchars($currentConfig['nit']); ?>">
                    <input type="hidden" name="cuenta_bancaria" value="<?php echo htmlspecialchars($currentConfig['cuenta_bancaria']); ?>">
                    <input type="hidden" name="banco" value="<?php echo htmlspecialchars($currentConfig['banco']); ?>">
                    <input type="hidden" name="mensaje_final" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>">
                    <input type="hidden" name="id_empresa" value="<?php echo (int)$currentConfig['id_empresa']; ?>">
                    <input type="hidden" name="id_sucursal" value="<?php echo (int)$currentConfig['id_sucursal']; ?>">
                    <input type="hidden" name="id_almacen" value="<?php echo (int)$currentConfig['id_almacen']; ?>">
                    <input type="hidden" name="mensajeria_tarifa_km" value="<?php echo htmlspecialchars((string)$currentConfig['mensajeria_tarifa_km']); ?>">
                    <input type="hidden" name="semana_inicio_dia" value="<?php echo (int)$currentConfig['semana_inicio_dia']; ?>">
                    <input type="hidden" name="reserva_limpieza_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_limpieza_pct']); ?>">
                    <input type="hidden" name="salario_elaborador_pct" value="<?php echo htmlspecialchars((string)$currentConfig['salario_elaborador_pct']); ?>">
                    <input type="hidden" name="reserva_negocio_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_negocio_pct']); ?>">
                    <input type="hidden" name="depreciacion_equipos_pct" value="<?php echo htmlspecialchars((string)$currentConfig['depreciacion_equipos_pct']); ?>">
                    <?php if (!empty($currentConfig['mostrar_materias_primas'])): ?><input type="hidden" name="mostrar_materias_primas" value="1"><?php endif; ?>
                    <?php if (!empty($currentConfig['mostrar_servicios'])): ?><input type="hidden" name="mostrar_servicios" value="1"><?php endif; ?>
                    <?php if (!empty($currentConfig['kiosco_solo_stock'])): ?><input type="hidden" name="kiosco_solo_stock" value="1"><?php endif; ?>
                    <input type="hidden" name="customer_display_insect" value="<?php echo htmlspecialchars($currentConfig['customer_display_insect'] ?? 'mosca'); ?>">
                    <input type="hidden" name="customer_display_chime_type" value="<?php echo htmlspecialchars($currentConfig['customer_display_chime_type']); ?>">
                    <input type="hidden" name="numero_tarjeta" value="<?php echo htmlspecialchars($currentConfig['numero_tarjeta']); ?>">
                    <input type="hidden" name="titular_tarjeta" value="<?php echo htmlspecialchars($currentConfig['titular_tarjeta']); ?>">
                    <input type="hidden" name="banco_tarjeta" value="<?php echo htmlspecialchars($currentConfig['banco_tarjeta']); ?>">
                    <input type="hidden" name="facebook_url" value="<?php echo htmlspecialchars($currentConfig['facebook_url']); ?>">
                    <input type="hidden" name="twitter_url" value="<?php echo htmlspecialchars($currentConfig['twitter_url']); ?>">
                    <input type="hidden" name="instagram_url" value="<?php echo htmlspecialchars($currentConfig['instagram_url']); ?>">
                    <input type="hidden" name="youtube_url" value="<?php echo htmlspecialchars($currentConfig['youtube_url']); ?>">
                    <input type="hidden" name="ticket_logo" value="<?php echo htmlspecialchars($currentConfig['ticket_logo']); ?>">
                    <input type="hidden" name="ticket_slogan" value="<?php echo htmlspecialchars($currentConfig['ticket_slogan']); ?>">
                    <?php if (!empty($currentConfig['ticket_mostrar_uuid'])): ?><input type="hidden" name="ticket_mostrar_uuid" value="1"><?php endif; ?>
                    <?php if (!empty($currentConfig['ticket_mostrar_canal'])): ?><input type="hidden" name="ticket_mostrar_canal" value="1"><?php endif; ?>
                    <?php if (!empty($currentConfig['ticket_mostrar_cajero'])): ?><input type="hidden" name="ticket_mostrar_cajero" value="1"><?php endif; ?>
                    <?php if (!empty($currentConfig['ticket_mostrar_qr'])): ?><input type="hidden" name="ticket_mostrar_qr" value="1"><?php endif; ?>
                    <?php if (!empty($currentConfig['ticket_mostrar_items_count'])): ?><input type="hidden" name="ticket_mostrar_items_count" value="1"><?php endif; ?>
                    <input type="hidden" name="tipo_cambio_usd" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_usd']); ?>">
                    <input type="hidden" name="tipo_cambio_mlc" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_mlc']); ?>">
                    <input type="hidden" name="moneda_default_pos" value="<?php echo htmlspecialchars($currentConfig['moneda_default_pos']); ?>">
                    <?php foreach (($currentConfig['categorias_ocultas'] ?? []) as $hiddenCategory): ?>
                        <input type="hidden" name="categorias_ocultas[]" value="<?php echo htmlspecialchars($hiddenCategory); ?>">
                    <?php endforeach; ?>
                    <p class="text-muted small">Estas llaves siguen guardándose en `pos.cfg` porque también alimentan flows web y shop.</p>
                    <div class="row g-2">
                        <?php foreach ($allNotificationKeys as $key): $enabled = !array_key_exists($key, $currentConfig['notification_type_settings'] ?? []) || !empty($currentConfig['notification_type_settings'][$key]); ?>
                            <div class="col-md-6">
                                <input type="hidden" name="notification_type_keys[]" value="<?php echo htmlspecialchars($key); ?>">
                                <label class="notification-switch">
                                    <input class="form-check-input" type="checkbox" name="notification_type_enabled[]" value="<?php echo htmlspecialchars($key); ?>" <?php echo $enabled ? 'checked' : ''; ?>>
                                    <span class="switch-copy">
                                        <span class="switch-title"><?php echo htmlspecialchars($notificationTypeLabels[$key] ?? $key); ?></span>
                                        <span class="switch-key"><?php echo htmlspecialchars($key); ?></span>
                                    </span>
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <button type="submit" class="btn btn-warning mt-3">Guardar notificaciones</button>
                </form>
            </div>
        </div>
    </div>

    <div class="cfg-tab d-none" data-tab-panel="estilo">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="form_action" value="save_shop_config">
            <input type="hidden" name="tienda_nombre" value="<?php echo htmlspecialchars($currentConfig['tienda_nombre']); ?>">
            <input type="hidden" name="direccion" value="<?php echo htmlspecialchars($currentConfig['direccion']); ?>">
            <input type="hidden" name="telefono" value="<?php echo htmlspecialchars($currentConfig['telefono']); ?>">
            <input type="hidden" name="email" value="<?php echo htmlspecialchars($currentConfig['email']); ?>">
            <input type="hidden" name="website" value="<?php echo htmlspecialchars($currentConfig['website']); ?>">
            <input type="hidden" name="nit" value="<?php echo htmlspecialchars($currentConfig['nit']); ?>">
            <input type="hidden" name="cuenta_bancaria" value="<?php echo htmlspecialchars($currentConfig['cuenta_bancaria']); ?>">
            <input type="hidden" name="banco" value="<?php echo htmlspecialchars($currentConfig['banco']); ?>">
            <input type="hidden" name="mensaje_final" value="<?php echo htmlspecialchars($currentConfig['mensaje_final']); ?>">
            <input type="hidden" name="id_empresa" value="<?php echo (int)$currentConfig['id_empresa']; ?>">
            <input type="hidden" name="id_sucursal" value="<?php echo (int)$currentConfig['id_sucursal']; ?>">
            <input type="hidden" name="id_almacen" value="<?php echo (int)$currentConfig['id_almacen']; ?>">
            <input type="hidden" name="mensajeria_tarifa_km" value="<?php echo htmlspecialchars((string)$currentConfig['mensajeria_tarifa_km']); ?>">
            <input type="hidden" name="semana_inicio_dia" value="<?php echo (int)$currentConfig['semana_inicio_dia']; ?>">
            <input type="hidden" name="reserva_limpieza_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_limpieza_pct']); ?>">
            <input type="hidden" name="salario_elaborador_pct" value="<?php echo htmlspecialchars((string)$currentConfig['salario_elaborador_pct']); ?>">
            <input type="hidden" name="reserva_negocio_pct" value="<?php echo htmlspecialchars((string)$currentConfig['reserva_negocio_pct']); ?>">
            <input type="hidden" name="depreciacion_equipos_pct" value="<?php echo htmlspecialchars((string)$currentConfig['depreciacion_equipos_pct']); ?>">
            <?php if (!empty($currentConfig['mostrar_materias_primas'])): ?><input type="hidden" name="mostrar_materias_primas" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['mostrar_servicios'])): ?><input type="hidden" name="mostrar_servicios" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['kiosco_solo_stock'])): ?><input type="hidden" name="kiosco_solo_stock" value="1"><?php endif; ?>
            <input type="hidden" name="customer_display_insect" value="<?php echo htmlspecialchars($currentConfig['customer_display_insect'] ?? 'mosca'); ?>">
            <input type="hidden" name="customer_display_chime_type" value="<?php echo htmlspecialchars($currentConfig['customer_display_chime_type']); ?>">
            <input type="hidden" name="numero_tarjeta" value="<?php echo htmlspecialchars($currentConfig['numero_tarjeta']); ?>">
            <input type="hidden" name="titular_tarjeta" value="<?php echo htmlspecialchars($currentConfig['titular_tarjeta']); ?>">
            <input type="hidden" name="banco_tarjeta" value="<?php echo htmlspecialchars($currentConfig['banco_tarjeta']); ?>">
            <input type="hidden" name="facebook_url" value="<?php echo htmlspecialchars($currentConfig['facebook_url']); ?>">
            <input type="hidden" name="twitter_url" value="<?php echo htmlspecialchars($currentConfig['twitter_url']); ?>">
            <input type="hidden" name="instagram_url" value="<?php echo htmlspecialchars($currentConfig['instagram_url']); ?>">
            <input type="hidden" name="youtube_url" value="<?php echo htmlspecialchars($currentConfig['youtube_url']); ?>">
            <input type="hidden" name="ticket_logo" value="<?php echo htmlspecialchars($currentConfig['ticket_logo']); ?>">
            <input type="hidden" name="ticket_slogan" value="<?php echo htmlspecialchars($currentConfig['ticket_slogan']); ?>">
            <?php if (!empty($currentConfig['ticket_mostrar_uuid'])): ?><input type="hidden" name="ticket_mostrar_uuid" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['ticket_mostrar_canal'])): ?><input type="hidden" name="ticket_mostrar_canal" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['ticket_mostrar_cajero'])): ?><input type="hidden" name="ticket_mostrar_cajero" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['ticket_mostrar_qr'])): ?><input type="hidden" name="ticket_mostrar_qr" value="1"><?php endif; ?>
            <?php if (!empty($currentConfig['ticket_mostrar_items_count'])): ?><input type="hidden" name="ticket_mostrar_items_count" value="1"><?php endif; ?>
            <input type="hidden" name="tipo_cambio_usd" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_usd']); ?>">
            <input type="hidden" name="tipo_cambio_mlc" value="<?php echo htmlspecialchars((string)$currentConfig['tipo_cambio_mlc']); ?>">
            <input type="hidden" name="moneda_default_pos" value="<?php echo htmlspecialchars($currentConfig['moneda_default_pos']); ?>">
            <input type="hidden" name="vapid_public_key" value="<?php echo htmlspecialchars($currentConfig['vapid_public_key'] ?? ''); ?>">
            <?php foreach (($currentConfig['categorias_ocultas'] ?? []) as $hiddenCategory): ?><input type="hidden" name="categorias_ocultas[]" value="<?php echo htmlspecialchars($hiddenCategory); ?>"><?php endforeach; ?>
            <?php foreach (($currentConfig['notification_type_settings'] ?? []) as $key => $enabled): ?><input type="hidden" name="notification_type_keys[]" value="<?php echo htmlspecialchars($key); ?>"><?php if ($enabled): ?><input type="hidden" name="notification_type_enabled[]" value="<?php echo htmlspecialchars($key); ?>"><?php endif; ?><?php endforeach; ?>
            <?php foreach (($currentConfig['metodos_pago'] ?? []) as $idx => $metodo): ?>
                <input type="hidden" name="metodo_id[]" value="<?php echo htmlspecialchars($metodo['id'] ?? ''); ?>">
                <input type="hidden" name="metodo_nombre[]" value="<?php echo htmlspecialchars($metodo['nombre'] ?? ''); ?>">
                <input type="hidden" name="metodo_icono[]" value="<?php echo htmlspecialchars($metodo['icono'] ?? ''); ?>">
                <input type="hidden" name="metodo_color[]" value="<?php echo htmlspecialchars($metodo['color_bootstrap'] ?? ''); ?>">
                <input type="hidden" name="metodo_texto_especial[]" value="<?php echo htmlspecialchars($metodo['texto_especial'] ?? ''); ?>">
                <?php if (!empty($metodo['activo'])): ?><input type="hidden" name="metodo_activo[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['requiere_codigo'])): ?><input type="hidden" name="metodo_requiere_codigo[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['aplica_pos'])): ?><input type="hidden" name="metodo_aplica_pos[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['aplica_shop'])): ?><input type="hidden" name="metodo_aplica_shop[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['es_transferencia'])): ?><input type="hidden" name="metodo_es_transferencia[]" value="<?php echo $idx; ?>"><?php endif; ?>
                <?php if (!empty($metodo['es_especial'])): ?><input type="hidden" name="metodo_es_especial[]" value="<?php echo $idx; ?>"><?php endif; ?>
            <?php endforeach; ?>

            <div class="card">
                <div class="card-header fw-bold text-primary">🎨 Identidad visual del sistema</div>
                <div class="card-body row g-4">
                    <div class="col-lg-6">
                        <div class="brand-preview">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="brand-logo-preview">
                                    <?php if (!empty($currentConfig['marca_sistema_logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($currentConfig['marca_sistema_logo']); ?>" alt="Logo del sistema">
                                    <?php else: ?>
                                        <i class="fas fa-cubes brand-logo-placeholder"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="small text-uppercase text-muted fw-bold">Sistema</div>
                                    <div class="fs-5 fw-bold"><?php echo htmlspecialchars($currentConfig['marca_sistema_nombre'] ?? 'PalWeb POS Marinero'); ?></div>
                                    <div class="small-muted">Marca principal del ERP/POS.</div>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Nombre del sistema</label>
                                    <input type="text" name="marca_sistema_nombre" class="form-control" value="<?php echo htmlspecialchars($currentConfig['marca_sistema_nombre'] ?? 'PalWeb POS Marinero'); ?>" placeholder="Ej: PalWeb POS Marinero">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Logo del sistema</label>
                                    <input type="file" name="marca_sistema_logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                                    <div class="form-text">Sube JPG, PNG o WebP. Máximo 2MB.</div>
                                </div>
                                <?php if (!empty($currentConfig['marca_sistema_logo'])): ?>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="marca_sistema_logo_remove" id="marca_sistema_logo_remove" value="1">
                                            <label class="form-check-label text-danger fw-semibold" for="marca_sistema_logo_remove">Eliminar logo actual del sistema</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="brand-preview">
                            <div class="d-flex align-items-center gap-3 mb-3">
                                <div class="brand-logo-preview">
                                    <?php if (!empty($currentConfig['marca_empresa_logo'])): ?>
                                        <img src="<?php echo htmlspecialchars($currentConfig['marca_empresa_logo']); ?>" alt="Logo de la empresa">
                                    <?php else: ?>
                                        <i class="fas fa-store brand-logo-placeholder"></i>
                                    <?php endif; ?>
                                </div>
                                <div>
                                    <div class="small text-uppercase text-muted fw-bold">Empresa</div>
                                    <div class="fs-5 fw-bold"><?php echo htmlspecialchars($currentConfig['marca_empresa_nombre'] ?? ($currentConfig['tienda_nombre'] ?? 'MI TIENDA')); ?></div>
                                    <div class="small-muted">Marca comercial visible para tu negocio.</div>
                                </div>
                            </div>
                            <div class="row g-3">
                                <div class="col-12">
                                    <label class="form-label">Nombre de la empresa</label>
                                    <input type="text" name="marca_empresa_nombre" class="form-control" value="<?php echo htmlspecialchars($currentConfig['marca_empresa_nombre'] ?? ($currentConfig['tienda_nombre'] ?? 'MI TIENDA')); ?>" placeholder="Ej: Marinero Restaurante">
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Logo de la empresa</label>
                                    <input type="file" name="marca_empresa_logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                                    <div class="form-text">Úsalo para encabezados, splash o materiales de marca.</div>
                                </div>
                                <?php if (!empty($currentConfig['marca_empresa_logo'])): ?>
                                    <div class="col-12">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="marca_empresa_logo_remove" id="marca_empresa_logo_remove" value="1">
                                            <label class="form-check-label text-danger fw-semibold" for="marca_empresa_logo_remove">Eliminar logo actual de la empresa</label>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="card">
                <div class="card-header fw-bold text-primary">🌈 Hero y estilo base</div>
                <div class="card-body row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Color Primario (Gradiente Izq)</label>
                        <input type="color" name="hero_color_1" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars($currentConfig['hero_color_1'] ?? '#0f766e'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Color Secundario (Gradiente Der)</label>
                        <input type="color" name="hero_color_2" class="form-control form-control-color w-100" value="<?php echo htmlspecialchars($currentConfig['hero_color_2'] ?? '#15803d'); ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label d-block">Opciones del Banner</label>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="hero_mostrar_usuario" id="hero_mostrar_usuario" <?php echo !empty($currentConfig['hero_mostrar_usuario']) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="hero_mostrar_usuario">Mostrar nombre de usuario</label>
                        </div>
                        <div class="form-check form-switch mt-2">
                            <input class="form-check-input" type="checkbox" name="hero_mostrar_logo" id="hero_mostrar_logo" <?php echo ($currentConfig['hero_mostrar_logo'] ?? true) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="hero_mostrar_logo">Mostrar logo en esquina (desktop)</label>
                        </div>
                    </div>
                    <div class="col-12 mt-3">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-1"></i> Los nombres y logos quedan guardados en <strong>pos.cfg</strong>. Los colores siguen afectando el estilo <strong>Inventory Suite</strong> usado por Producción, Compras, KDS y módulos relacionados.
                        </div>
                    </div>
                </div>
            </div>

            <div class="card mt-4">
                <div class="card-header fw-bold text-primary">🖥️ Logo del Login POS</div>
                <div class="card-body row g-4 align-items-center">
                    <div class="col-md-3 text-center">
                        <?php $posLoginLogo = $currentConfig['marca_pos_login_logo'] ?? ''; ?>
                        <?php if (!empty($posLoginLogo) && file_exists(__DIR__ . '/' . $posLoginLogo)): ?>
                            <img src="<?php echo htmlspecialchars($posLoginLogo); ?>" alt="Logo Login POS" class="img-fluid rounded shadow-sm" style="max-height: 80px;">
                        <?php else: ?>
                            <div class="bg-light rounded d-flex align-items-center justify-content-center" style="height: 80px;">
                                <i class="fas fa-image text-muted fa-2x"></i>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-9">
                        <label class="form-label">Imagen personalizada para la pantalla de PIN</label>
                        <input type="file" name="marca_pos_login_logo" class="form-control" accept="image/jpeg,image/png,image/webp">
                        <div class="form-text">Sube una imagen rectangular (banner). Se mostrará arriba del teclado numérico.</div>
                        <?php if (!empty($posLoginLogo)): ?>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="marca_pos_login_logo_remove" id="marca_pos_login_logo_remove" value="1">
                                <label class="form-check-label text-danger fw-semibold" for="marca_pos_login_logo_remove">Eliminar logo actual</label>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-primary btn-lg w-100 shadow">Guardar identidad corporativa</button>
        </form>
    </div>

    <!-- ═══════════════════════════════════════════════════════════ -->
    <!-- TAB: BANNERS SHOP                                           -->
    <!-- ═══════════════════════════════════════════════════════════ -->
    <div class="cfg-tab d-none" data-tab-panel="banners">
        <form method="post" enctype="multipart/form-data">
            <input type="hidden" name="form_action" value="save_banners">
            <div class="card">
                <div class="card-header fw-bold text-primary">
                    <i class="fas fa-image me-2"></i>Carrusel de Publicidad (Shop)
                </div>
                <div class="card-body">
                    <p class="text-muted small mb-4">Configura los 4 banners del carrusel. Puedes subir imagen, ajustar su tamaño y personalizar los textos.</p>
                    <div class="row g-4">
                        <?php
                        $banners = $currentConfig['banners'] ?? [];
                        for ($i = 0; $i < 4; $i++):
                            $b       = $banners[$i] ?? ['titulo' => '', 'subtitulo' => '', 'imagen' => '', 'color_clase' => 'gradient-' . ($i + 1), 'bg_size' => 'cover'];
                            $bImg    = trim((string)($b['imagen'] ?? ''));
                            $bHasImg = $bImg !== '' && file_exists(__DIR__ . '/' . $bImg);
                            $bSize   = htmlspecialchars((string)($b['bg_size'] ?? 'cover'), ENT_QUOTES);
                            $bSizeNum = (is_numeric(rtrim($bSize, '%'))) ? (int)rtrim($bSize, '%') : 100;
                            $bgStyle = $bHasImg
                                ? "background-image:url('" . htmlspecialchars($bImg, ENT_QUOTES) . "?v=" . filemtime(__DIR__ . '/' . $bImg) . "');background-size:" . $bSize . ";background-position:center;"
                                : '';
                            $previewClass = $bHasImg ? '' : htmlspecialchars($b['color_clase'] ?? 'gradient-' . ($i + 1), ENT_QUOTES);
                        ?>
                        <div class="col-md-6">
                            <div class="banner-edit-card">
                                <div class="d-flex justify-content-between align-items-center mb-3">
                                    <h6 class="fw-bold mb-0">Banner #<?php echo $i + 1; ?></h6>
                                    <?php if ($bHasImg): ?>
                                        <span class="badge bg-success"><i class="fas fa-image me-1"></i>Imagen activa</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="fas fa-palette me-1"></i>Gradiente</span>
                                    <?php endif; ?>
                                </div>

                                <!-- Preview en vivo -->
                                <div class="banner-preview <?php echo $previewClass; ?>"
                                     id="banner_preview_<?php echo $i; ?>"
                                     style="<?php echo $bgStyle; ?>">
                                    <div class="text-center px-2" style="text-shadow:0 1px 4px rgba(0,0,0,.5)">
                                        <div id="bprev_title_<?php echo $i; ?>" style="font-size:.9rem;font-weight:700;">
                                            <?php echo htmlspecialchars($b['titulo']); ?>
                                        </div>
                                        <div id="bprev_sub_<?php echo $i; ?>" style="font-size:.65rem;opacity:.9;">
                                            <?php echo htmlspecialchars($b['subtitulo']); ?>
                                        </div>
                                    </div>
                                </div>

                                <!-- Imagen actual -->
                                <?php if ($bHasImg): ?>
                                <div class="d-flex align-items-center gap-3 mt-3 p-2 border rounded bg-light">
                                    <img src="<?php echo htmlspecialchars($bImg, ENT_QUOTES); ?>?v=<?php echo filemtime(__DIR__ . '/' . $bImg); ?>"
                                         style="height:56px;max-width:120px;object-fit:cover;border-radius:6px;border:1px solid #ddd;" alt="Banner actual">
                                    <div class="flex-grow-1">
                                        <div class="small fw-semibold text-truncate"><?php echo htmlspecialchars(basename($bImg)); ?></div>
                                        <div class="form-check mt-1">
                                            <input class="form-check-input" type="checkbox" name="banner_remove_<?php echo $i; ?>" id="banner_remove_<?php echo $i; ?>" value="1"
                                                   onchange="bannerRemoveToggle(<?php echo $i; ?>, this.checked)">
                                            <label class="form-check-label small text-danger fw-semibold" for="banner_remove_<?php echo $i; ?>">Eliminar imagen</label>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <!-- Subir nueva imagen -->
                                <div class="mt-3">
                                    <label class="form-label small fw-semibold">
                                        <?php echo $bHasImg ? 'Reemplazar imagen' : 'Subir imagen de fondo'; ?>
                                        <span class="text-muted fw-normal">(JPG/PNG/WebP, máx 2MB)</span>
                                    </label>
                                    <input type="file" name="banner_file_<?php echo $i; ?>" class="form-control form-control-sm"
                                           accept="image/jpeg,image/png,image/webp"
                                           onchange="bannerFilePreview(<?php echo $i; ?>, this)">
                                </div>

                                <!-- Tamaño de imagen -->
                                <?php if ($bHasImg): ?>
                                <div class="mt-3">
                                    <label class="form-label small fw-semibold d-flex justify-content-between align-items-center">
                                        Tamaño de imagen
                                        <span id="bsize_lbl_<?php echo $i; ?>" class="badge bg-primary"><?php echo $bSize; ?></span>
                                    </label>
                                    <input type="range" class="form-range" min="10" max="300" step="5"
                                           value="<?php echo $bSizeNum; ?>"
                                           id="bsize_range_<?php echo $i; ?>"
                                           oninput="bannerSizeRange(<?php echo $i; ?>, this.value)">
                                    <div class="d-flex gap-1 mt-1">
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                                onclick="bannerSizeSet(<?php echo $i; ?>, 'cover')">Cover</button>
                                        <button type="button" class="btn btn-sm btn-outline-secondary py-0 px-2"
                                                onclick="bannerSizeSet(<?php echo $i; ?>, 'contain')">Contain</button>
                                        <span class="text-muted small ms-1 align-self-center">Cover = llenar · Contain = ajustar</span>
                                    </div>
                                </div>
                                <?php else: ?>
                                <div class="mt-2 small text-muted"><i class="fas fa-info-circle me-1"></i>Sube una imagen para activar el control de tamaño.</div>
                                <?php endif; ?>
                                <!-- Campo oculto con el valor final de bg_size -->
                                <input type="hidden" name="banner_bg_size[]" id="bsize_<?php echo $i; ?>"
                                       value="<?php echo $bSize; ?>">

                                <!-- Textos -->
                                <div class="mt-3 row g-2">
                                    <div class="col-12">
                                        <label class="form-label small fw-semibold">Título</label>
                                        <input type="text" name="banner_titulo[]" class="form-control form-control-sm"
                                               placeholder="Ej: 🚀 Envíos a domicilio"
                                               value="<?php echo htmlspecialchars($b['titulo']); ?>"
                                               oninput="document.getElementById('bprev_title_<?php echo $i; ?>').textContent=this.value">
                                    </div>
                                    <div class="col-12">
                                        <label class="form-label small fw-semibold">Subtítulo</label>
                                        <input type="text" name="banner_subtitulo[]" class="form-control form-control-sm"
                                               placeholder="Ej: Calcula el costo a tu barrio"
                                               value="<?php echo htmlspecialchars($b['subtitulo']); ?>"
                                               oninput="document.getElementById('bprev_sub_<?php echo $i; ?>').textContent=this.value">
                                    </div>
                                </div>

                            </div>
                        </div>
                        <?php endfor; ?>
                    </div>
                    <div class="section-note mt-3">
                        <i class="fas fa-info-circle me-1"></i> Los banners se muestran en el carrusel de <code>shop.php</code>. Sin imagen activa se usa el gradiente de color.
                    </div>
                </div>
            </div>
            <button type="submit" class="btn btn-primary btn-lg w-100 shadow"><i class="fas fa-save me-2"></i>Guardar Banners</button>
        </form>
    </div>

</main>
<script>
const POSCFG_ACTIVE_TAB = <?= json_encode($activeTab, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

function ensureActiveTabInputs(name) {
    document.querySelectorAll('form').forEach(form => {
        let input = form.querySelector('input[name="active_tab"]');
        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'active_tab';
            form.appendChild(input);
        }
        input.value = name;
    });
}

function showCfgTab(name, btn) {
    document.querySelectorAll('.cfg-tab').forEach(el => el.classList.add('d-none'));
    document.querySelector('[data-tab-panel="' + name + '"]')?.classList.remove('d-none');
    document.querySelectorAll('#sidebar .sidebar-nav-link').forEach(el => el.classList.remove('active'));
    btn?.classList.add('active');
    ensureActiveTabInputs(name);
    try { localStorage.setItem('pos_config2_active_tab', name); } catch (e) {}
}

function toggleSidebar() {
    document.getElementById('sidebar').classList.toggle('collapsed');
    document.getElementById('main-content').classList.toggle('expanded');
}

function normalizeText(value) {
    return (value || '').toString().toLowerCase().trim();
}

function filterUserRows() {
    const q = normalizeText(document.getElementById('userTableSearch')?.value);
    const role = normalizeText(document.getElementById('userRoleFilter')?.value);
    const status = normalizeText(document.getElementById('userStatusFilter')?.value);
    document.querySelectorAll('#usersCrudTable [data-user-row="1"]').forEach(row => {
        const text = normalizeText(row.dataset.search);
        const rowRole = normalizeText(row.dataset.role);
        const rowState = normalizeText(row.dataset.state);
        const rowLogin = normalizeText(row.dataset.login);
        const matchesText = !q || text.includes(q);
        const matchesRole = !role || rowRole === role;
        const matchesStatus =
            !status ||
            rowState === status ||
            (status === 'login_off' && rowLogin === 'login_off');
        row.style.display = (matchesText && matchesRole && matchesStatus) ? '' : 'none';
    });
}

function filterCashierRows() {
    const q = normalizeText(document.getElementById('cashierTableSearch')?.value);
    const role = normalizeText(document.getElementById('cashierRoleFilter')?.value);
    const status = normalizeText(document.getElementById('cashierStatusFilter')?.value);
    document.querySelectorAll('#cashiersCrudTable [data-cashier-row="1"]').forEach(row => {
        const text = normalizeText(row.dataset.search);
        const rowRole = normalizeText(row.dataset.role);
        const rowState = normalizeText(row.dataset.state);
        const matchesText = !q || text.includes(q);
        const matchesRole = !role || rowRole === role;
        const matchesState = !status || rowState === status;
        row.style.display = (matchesText && matchesRole && matchesState) ? '' : 'none';
    });
}

function bindDependentSelects(companyId, branchId, warehouseId) {
    const company = document.getElementById(companyId);
    const branch = document.getElementById(branchId);
    const warehouse = document.getElementById(warehouseId);
    if (!company || !branch || !warehouse) return;

    const filterBranch = () => {
        const companyValue = company.value;
        let branchHasSelected = false;
        Array.from(branch.options).forEach((opt, idx) => {
            if (idx === 0) {
                opt.hidden = false;
                return;
            }
            const visible = !companyValue || opt.dataset.empresaId === companyValue;
            opt.hidden = !visible;
            if (!visible && opt.selected) {
                branch.selectedIndex = 0;
            }
            if (visible && opt.selected) {
                branchHasSelected = true;
            }
        });
        if (!branchHasSelected && branch.selectedIndex > 0 && branch.options[branch.selectedIndex]?.hidden) {
            branch.selectedIndex = 0;
        }
        filterWarehouse();
    };

    const filterWarehouse = () => {
        const branchValue = branch.value;
        Array.from(warehouse.options).forEach((opt, idx) => {
            if (idx === 0) {
                opt.hidden = false;
                return;
            }
            const visible = !branchValue || opt.dataset.sucursalId === branchValue;
            opt.hidden = !visible;
            if (!visible && opt.selected) {
                warehouse.selectedIndex = 0;
            }
        });
    };

    company.addEventListener('change', filterBranch);
    branch.addEventListener('change', filterWarehouse);
    filterBranch();
}

function reindexMetodosPago() {
    document.querySelectorAll('#metodosPagoList .metodo-item').forEach((item, idx) => {
        const title = item.querySelector('.fw-semibold.small.text-secondary');
        if (title) title.innerHTML = '<i class="fas fa-grip-vertical me-1"></i> Método #' + (idx + 1);
        item.querySelectorAll('input[type="checkbox"]').forEach(chk => {
            chk.value = String(idx);
        });
        validateMetodoPagoItem(item);
    });
}

function addMetodoPagoRow() {
    const wrap = document.getElementById('metodosPagoList');
    if (!wrap) return;
    const div = document.createElement('div');
    div.className = 'metodo-item border rounded mb-2';
    div.setAttribute('draggable', 'true');
    div.innerHTML = `
        <div class="row g-2">
            <div class="col-md-2"><label class="form-label small">ID (inmutable)</label><input type="text" class="form-control form-control-sm mono fw-bold" name="metodo_id[]" value=""></div>
            <div class="col-md-2"><label class="form-label small">Nombre visible</label><input type="text" class="form-control form-control-sm" name="metodo_nombre[]" value=""></div>
            <div class="col-md-2"><label class="form-label small">Icono FontAwesome</label><input type="text" class="form-control form-control-sm" name="metodo_icono[]" value="fa-money-bill-wave"></div>
            <div class="col-md-2"><label class="form-label small">Color</label><select class="form-select form-select-sm" name="metodo_color[]">
                <option value="success">Verde</option>
                <option value="primary">Azul</option>
                <option value="secondary" selected>Gris</option>
                <option value="warning">Amarillo</option>
                <option value="danger">Rojo</option>
                <option value="info">Celeste</option>
                <option value="dark">Negro</option>
            </select></div>
            <div class="col-md-4">
                <label class="form-label small">Opciones</label>
                <div class="d-flex flex-wrap gap-2 align-items-center">
                    <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" name="metodo_activo[]" value="0" checked><label class="form-check-label small">Activo</label></div>
                    <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" name="metodo_aplica_pos[]" value="0" checked><label class="form-check-label small">POS</label></div>
                    <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" name="metodo_aplica_shop[]" value="0"><label class="form-check-label small">Shop</label></div>
                    <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" name="metodo_es_transferencia[]" value="0"><label class="form-check-label small">Transf.</label></div>
                    <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" name="metodo_es_especial[]" value="0"><label class="form-check-label small text-info fw-semibold">Especial</label></div>
                    <div class="form-check form-check-inline mb-0"><input class="form-check-input" type="checkbox" name="metodo_requiere_codigo[]" value="0"><label class="form-check-label small">Código</label></div>
                    <div class="d-flex gap-2 ms-auto">
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveMetodoPago(this, -1)">↑</button>
                        <button type="button" class="btn btn-sm btn-outline-secondary" onclick="moveMetodoPago(this, 1)">↓</button>
                        <button type="button" class="btn btn-outline-danger btn-sm" onclick="this.closest('.metodo-item').remove(); reindexMetodosPago();"><i class="fas fa-trash"></i></button>
                    </div>
                </div>
                <div class="especial-text-row">
                    <input type="text" class="form-control form-control-sm" name="metodo_texto_especial[]" value="" placeholder="Texto a mostrar al seleccionar este método">
                </div>
            </div>
            <div class="col-md-12 metodo-warn"></div>
        </div>
    `;
    wrap.appendChild(div);
    bindMetodoPagoItem(div);
    reindexMetodosPago();
}

function moveMetodoPago(button, direction) {
    const item = button.closest('.metodo-item');
    const wrap = document.getElementById('metodosPagoList');
    if (!item || !wrap) return;
    const sibling = direction < 0 ? item.previousElementSibling : item.nextElementSibling;
    if (!sibling) return;
    if (direction < 0) wrap.insertBefore(item, sibling);
    else wrap.insertBefore(sibling, item);
    reindexMetodosPago();
}

function validateMetodoPagoItem(item) {
    const warn = item.querySelector('.metodo-warn');
    if (!warn) return;
    const checked = name => !!item.querySelector(`input[name="${name}[]"]`)?.checked;
    const warnings = [];
    if (checked('metodo_es_transferencia') && !checked('metodo_requiere_codigo')) {
        warnings.push('Es transferencia pero no requiere código.');
    }
    if (checked('metodo_aplica_shop') && !checked('metodo_activo')) {
        warnings.push('Está inactivo pero marcado para shop.');
    }
    if (checked('metodo_es_especial') && !(item.querySelector('input[name="metodo_texto_especial[]"]')?.value || '').trim()) {
        warnings.push('Está marcado como especial y no tiene texto especial.');
    }
    if (!checked('metodo_aplica_pos') && !checked('metodo_aplica_shop')) {
        warnings.push('No aplica ni a POS ni a shop.');
    }
    warn.textContent = warnings.join(' ');
    warn.style.display = warnings.length ? 'block' : 'none';
}

function bindMetodoPagoItem(item) {
    item.querySelectorAll('input').forEach(el => {
        el.addEventListener('change', () => validateMetodoPagoItem(item));
        el.addEventListener('input', () => validateMetodoPagoItem(item));
    });
    item.addEventListener('dragstart', () => item.classList.add('dragging'));
    item.addEventListener('dragend', () => {
        item.classList.remove('dragging');
        reindexMetodosPago();
    });
    item.addEventListener('dragover', e => e.preventDefault());
    item.addEventListener('drop', e => {
        e.preventDefault();
        const wrap = document.getElementById('metodosPagoList');
        const dragging = wrap?.querySelector('.metodo-item.dragging');
        if (!dragging || dragging === item || !wrap) return;
        const rect = item.getBoundingClientRect();
        const before = (e.clientY - rect.top) < rect.height / 2;
        wrap.insertBefore(dragging, before ? item : item.nextElementSibling);
        reindexMetodosPago();
    });
}

const _ticketPreviewCfg = {
    nombre: <?= json_encode($currentConfig['tienda_nombre']) ?>,
    dir: <?= json_encode($currentConfig['direccion']) ?>,
    tel: <?= json_encode($currentConfig['telefono']) ?>,
    slogan: <?= json_encode($currentConfig['ticket_slogan'] ?? '') ?>,
    msgFinal: <?= json_encode($currentConfig['mensaje_final']) ?>,
    logoSrc: <?= json_encode($ticketLogoExists ? $ticketLogoPath . '?v=' . filemtime(__DIR__ . '/' . $ticketLogoPath) : '') ?>,
};

function escTicket(s) {
    return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}

function ticketSep() {
    return '<div style="border-top:1px dashed #999;margin:5px 0;"></div>';
}

function updateTicketPreview() {
    const target = document.getElementById('ticketPreview');
    if (!target) return;

    const nombre = document.querySelector('[name="tienda_nombre"]')?.value || _ticketPreviewCfg.nombre;
    const dir = document.querySelector('[name="direccion"]')?.value || _ticketPreviewCfg.dir;
    const tel = document.querySelector('[name="telefono"]')?.value || _ticketPreviewCfg.tel;
    const slogan = document.querySelector('[name="ticket_slogan"]')?.value ?? _ticketPreviewCfg.slogan;
    const msgFinal = document.querySelector('[name="mensaje_final"]')?.value ?? _ticketPreviewCfg.msgFinal;
    const showCajero = document.getElementById('chkMostrarCajero')?.checked;
    const showCanal = document.getElementById('chkMostrarCanal')?.checked;
    const showUuid = document.getElementById('chkMostrarUuid')?.checked;
    const showItems = document.getElementById('chkMostrarItemsCount')?.checked;
    const showQr = document.getElementById('chkMostrarQr')?.checked;
    const removeLogo = document.getElementById('ticket_logo_remove')?.checked;
    const logoSrc = removeLogo ? '' : (window._ticketLogoSrc ?? _ticketPreviewCfg.logoSrc);

    let h = '';
    h += '<div style="text-align:center;margin-bottom:6px;">';
    if (logoSrc) {
        h += `<img src="${logoSrc}" style="display:block;max-width:160px;max-height:52px;margin:0 auto 4px;" alt="">`;
    }
    h += `<strong style="font-size:13px;">${escTicket(nombre)}</strong>`;
    if (slogan) h += `<div style="font-size:9px;margin-top:1px;">${escTicket(slogan)}</div>`;
    h += `<div style="font-size:9px;color:#666;">${escTicket(dir)}</div>`;
    if (tel) h += `<div style="font-size:9px;color:#666;">Tel: ${escTicket(tel)}</div>`;
    h += '</div>';

    h += ticketSep();
    h += '<table style="width:100%;font-size:9px;border-collapse:collapse;">';
    h += '<tr><td>Ticket:</td><td style="text-align:right"><b>#000216</b></td></tr>';
    if (showUuid) h += '<tr><td>UUID:</td><td style="text-align:right;font-size:8px;color:#666;">WEB-1234567890</td></tr>';
    h += '<tr><td>Fecha:</td><td style="text-align:right">23/02/2026 09:02</td></tr>';
    if (showCajero) h += '<tr><td>Cajero:</td><td style="text-align:right"><b>Admin</b></td></tr>';
    if (showCanal) h += '<tr><td>Origen:</td><td style="text-align:right"><span style="background:#0ea5e9;color:#fff;padding:1px 5px;border-radius:10px;font-size:8px;">🌐 Web</span></td></tr>';
    h += '<tr><td>Método Pago:</td><td style="text-align:right"><b>Efectivo</b></td></tr>';
    h += '</table>';

    h += ticketSep();
    h += '<table style="width:100%;font-size:9px;border-collapse:collapse;">';
    h += '<tr><th style="text-align:left;border-bottom:1px solid #000;padding:2px 0;">Cant</th><th style="text-align:left;border-bottom:1px solid #000;padding:2px 0;">Descripción</th><th style="text-align:right;border-bottom:1px solid #000;padding:2px 0;">Total</th></tr>';
    h += '<tr><td>1.00</td><td>Café en sobre</td><td style="text-align:right">$40.00</td></tr>';
    h += '</table>';
    if (showItems) {
        h += '<div style="text-align:right;font-size:9px;font-weight:bold;border-top:1px solid #000;margin-top:3px;padding-top:2px;">Total Ítems: 1</div>';
    }

    h += '<div style="border:2px solid #000;padding:5px;margin-top:5px;">';
    h += '<table style="width:100%;font-size:9px;border-collapse:collapse;">';
    h += '<tr><td>Subtotal productos:</td><td style="text-align:right">$40.00</td></tr>';
    h += '<tr><td><b>Costo mensajería:</b></td><td style="text-align:right"><b>$250.00</b></td></tr>';
    h += '<tr style="border-top:1px dashed #000;"><td colspan="2"></td></tr>';
    h += '<tr><td><b style="font-size:11px;">TOTAL A PAGAR:</b></td><td style="text-align:right"><b style="font-size:14px;">$290.00</b></td></tr>';
    h += '<tr><td colspan="2" style="text-align:center;font-size:8px;border-top:1px dashed #000;padding-top:3px;">💳 Pagado con: <b>Efectivo</b></td></tr>';
    h += '</table></div>';

    h += ticketSep();
    if (msgFinal) {
        h += `<div style="text-align:center;font-weight:bold;font-size:10px;">${escTicket(msgFinal)}</div>`;
    }
    h += '<div style="text-align:center;font-size:8px;color:#999;margin-top:2px;">Sistema <?= htmlspecialchars($currentConfig['marca_sistema_nombre'] ?? 'PalWeb POS Marinero') ?> v3.0</div>';

    if (showQr) {
        h += ticketSep();
        h += '<div style="text-align:center;">';
        h += '<div style="font-size:8px;margin-bottom:3px;">Escanea para ver este ticket</div>';
        h += '<svg width="52" height="52" viewBox="0 0 52 52" style="display:inline-block;">';
        h += '<rect x="0" y="0" width="52" height="52" fill="white"/>';
        h += '<rect x="2" y="2" width="20" height="20" fill="none" stroke="#000" stroke-width="2"/>';
        h += '<rect x="6" y="6" width="12" height="12" fill="#000"/>';
        h += '<rect x="30" y="2" width="20" height="20" fill="none" stroke="#000" stroke-width="2"/>';
        h += '<rect x="34" y="6" width="12" height="12" fill="#000"/>';
        h += '<rect x="2" y="30" width="20" height="20" fill="none" stroke="#000" stroke-width="2"/>';
        h += '<rect x="6" y="34" width="12" height="12" fill="#000"/>';
        h += '<rect x="30" y="30" width="6" height="6" fill="#000"/><rect x="38" y="30" width="6" height="6" fill="#000"/>';
        h += '<rect x="30" y="38" width="6" height="6" fill="#000"/><rect x="46" y="46" width="6" height="6" fill="#000"/>';
        h += '</svg></div>';
    }

    target.innerHTML = h;
}

function onLogoFileChange(input) {
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        reader.onload = e => {
            window._ticketLogoSrc = e.target.result;
            updateTicketPreview();
        };
        reader.readAsDataURL(input.files[0]);
    }
}

/* ── Banner size controls ───────────────────────────────────── */
function bannerSizeRange(i, val) {
    const v = val + '%';
    document.getElementById('bsize_' + i).value = v;
    document.getElementById('bsize_lbl_' + i).textContent = v;
    const p = document.getElementById('banner_preview_' + i);
    if (p) p.style.backgroundSize = v;
}
function bannerSizeSet(i, val) {
    document.getElementById('bsize_' + i).value = val;
    document.getElementById('bsize_lbl_' + i).textContent = val;
    const p = document.getElementById('banner_preview_' + i);
    if (p) p.style.backgroundSize = val;
    // Sync slider: cover/contain → show 100 as default position
    const range = document.getElementById('bsize_range_' + i);
    if (range) range.value = 100;
}
function bannerFilePreview(i, input) {
    if (!input.files || !input.files[0]) return;
    const reader = new FileReader();
    reader.onload = function(e) {
        const p = document.getElementById('banner_preview_' + i);
        if (!p) return;
        p.style.backgroundImage = "url('" + e.target.result + "')";
        p.style.backgroundSize  = document.getElementById('bsize_' + i)?.value || 'cover';
        p.style.backgroundPosition = 'center';
        p.className = 'banner-preview'; // quita gradiente si había
    };
    reader.readAsDataURL(input.files[0]);
}
function bannerRemoveToggle(i, checked) {
    const p = document.getElementById('banner_preview_' + i);
    if (!p) return;
    if (checked) {
        p.style.backgroundImage = '';
        // Restore gradient class from data attribute if available
        const gradClass = 'gradient-' + (i + 1);
        p.className = 'banner-preview ' + gradClass;
    }
}

document.addEventListener('DOMContentLoaded', () => {
    const rememberedTab = (() => {
        try { return localStorage.getItem('pos_config2_active_tab') || ''; } catch (e) { return ''; }
    })();
    const initialTab = POSCFG_ACTIVE_TAB || rememberedTab || 'shop';
    const initialBtn = document.querySelector(`#sidebar .sidebar-nav-link[data-tab="${initialTab}"]`) || document.querySelector('#sidebar .sidebar-nav-link[data-tab="shop"]');
    showCfgTab(initialTab, initialBtn);
    document.querySelectorAll('#metodosPagoList .metodo-item').forEach(bindMetodoPagoItem);
    reindexMetodosPago();
    bindDependentSelects('shopEmpresaSelect', 'shopSucursalSelect', 'shopAlmacenSelect');
    bindDependentSelects('userEmpresaSelect', 'userSucursalSelect', 'userAlmacenSelect');
    bindDependentSelects('cashierEmpresaSelect', 'cashierSucursalSelect', 'cashierAlmacenSelect');
    document.getElementById('userTableSearch')?.addEventListener('input', filterUserRows);
    document.getElementById('userRoleFilter')?.addEventListener('change', filterUserRows);
    document.getElementById('userStatusFilter')?.addEventListener('change', filterUserRows);
    document.getElementById('cashierTableSearch')?.addEventListener('input', filterCashierRows);
    document.getElementById('cashierRoleFilter')?.addEventListener('change', filterCashierRows);
    document.getElementById('cashierStatusFilter')?.addEventListener('change', filterCashierRows);
    filterUserRows();
    filterCashierRows();
    updateTicketPreview();
    ['tienda_nombre','direccion','telefono','ticket_slogan','mensaje_final'].forEach(name => {
        document.querySelector(`[name="${name}"]`)?.addEventListener('input', updateTicketPreview);
    });
});
</script>
<script src="assets/js/bootstrap.bundle.min.js"></script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
