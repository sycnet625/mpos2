<?php
// ARCHIVO: admin_migracion.php  — Gestor de Migraciones de Base de Datos
ini_set('display_errors', 0);
ini_set('max_execution_time', 300);
session_start();
require_once 'db.php';
require_once 'config_loader.php';

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php'); exit;
}

// Asegurar tabla de control de migraciones
$pdo->exec("CREATE TABLE IF NOT EXISTS `migrations` (
    `id` INT AUTO_INCREMENT PRIMARY KEY,
    `migration` VARCHAR(255) NOT NULL,
    `batch` INT NOT NULL DEFAULT 1,
    UNIQUE KEY `uk_migration` (`migration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

// ─────────────────────────────────────────────────────────────────────────────
// CATÁLOGO DE MIGRACIONES
// ─────────────────────────────────────────────────────────────────────────────
$allMigrations = [

// ── MÓDULO: Core POS ─────────────────────────────────────────────────────────
['id'=>'m001_productos_core','module'=>'Core POS','icon'=>'fa-boxes','description'=>'Tabla productos (base)',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `productos` (`codigo` varchar(50) NOT NULL,`nombre` varchar(200) NOT NULL,`precio` decimal(12,2) DEFAULT 0.00,`costo` decimal(12,2) DEFAULT 0.00,`precio_mayorista` decimal(12,2) DEFAULT 0.00,`activo` tinyint(1) DEFAULT 1,`version_row` int(11) DEFAULT 0,`id_empresa` int(11) DEFAULT 1,`categoria` varchar(100) DEFAULT NULL,`stock_minimo` decimal(12,3) DEFAULT 0.000,`fecha_vencimiento` date DEFAULT NULL,`es_elaborado` tinyint(1) DEFAULT 0,`es_materia_prima` tinyint(1) DEFAULT 0,`es_servicio` tinyint(1) DEFAULT 0,`es_cocina` tinyint(1) DEFAULT 0,`unidad_medida` varchar(20) DEFAULT 'und',`descripcion` text DEFAULT NULL,`impuesto` decimal(5,2) DEFAULT 0.00,`peso` decimal(10,3) DEFAULT 0.000,`color` varchar(20) DEFAULT NULL,`es_web` tinyint(1) DEFAULT 0,`es_pos` tinyint(1) DEFAULT 1,`uuid` varchar(80) DEFAULT NULL,`id_sucursal_origen` int(11) DEFAULT 1,`updated_at` timestamp DEFAULT current_timestamp() ON UPDATE current_timestamp(),PRIMARY KEY (`codigo`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m002_productos_columnas_nuevas','module'=>'Core POS','icon'=>'fa-plus-circle','description'=>'Columnas nuevas en productos (barcodes, favorito, combo, oferta, reservable)',
'sqls'=>[
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `codigo_barra_1` VARCHAR(64) NULL AFTER `codigo`",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `codigo_barra_2` VARCHAR(64) NULL AFTER `codigo_barra_1`",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `favorito` TINYINT(1) NOT NULL DEFAULT 0",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `es_combo` TINYINT(1) NOT NULL DEFAULT 0",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `es_reservable` TINYINT(1) DEFAULT 0",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `precio_oferta` DECIMAL(12,2) DEFAULT NULL",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `tiene_variantes` TINYINT(1) DEFAULT 0",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `variantes_json` LONGTEXT DEFAULT NULL",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `etiqueta_web` VARCHAR(100) DEFAULT NULL",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `etiqueta_color` VARCHAR(20) DEFAULT NULL",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `sucursales_web` VARCHAR(50) DEFAULT NULL",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `es_suc1` TINYINT(1) DEFAULT 1",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `es_suc2` TINYINT(1) DEFAULT 0",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `es_suc3` TINYINT(1) DEFAULT 0",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `es_suc4` TINYINT(1) DEFAULT 0",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `es_suc5` TINYINT(1) DEFAULT 0",
"ALTER TABLE `productos` ADD COLUMN IF NOT EXISTS `es_suc6` TINYINT(1) DEFAULT 0",
]],

['id'=>'m003_stock_kardex','module'=>'Core POS','icon'=>'fa-warehouse','description'=>'stock_almacen, kardex y almacenes',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `almacenes` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(100) NOT NULL,`id_empresa` INT DEFAULT 1,`id_sucursal` INT DEFAULT 1,`activo` TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `stock_almacen` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_almacen` INT NOT NULL,`id_producto` VARCHAR(50) NOT NULL,`cantidad` DECIMAL(12,3) DEFAULT 0,`ultima_actualizacion` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp(),`id_sucursal` INT DEFAULT 1,`codigo_temp` VARCHAR(50) DEFAULT NULL,UNIQUE KEY `uk_stock` (`id_almacen`,`id_producto`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"ALTER TABLE `stock_almacen` ADD COLUMN IF NOT EXISTS `id_sucursal` INT DEFAULT 1",
"CREATE TABLE IF NOT EXISTS `kardex` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_producto` VARCHAR(50) NOT NULL,`id_almacen` INT NOT NULL,`fecha` DATETIME DEFAULT current_timestamp(),`tipo_movimiento` VARCHAR(20) NOT NULL,`cantidad` DECIMAL(12,3) NOT NULL,`saldo_anterior` DECIMAL(12,3) DEFAULT 0,`saldo_actual` DECIMAL(12,3) DEFAULT 0,`referencia` VARCHAR(100) DEFAULT NULL,`costo_unitario` DECIMAL(12,2) DEFAULT 0,`usuario` VARCHAR(100) DEFAULT NULL,`uuid` VARCHAR(80) DEFAULT NULL,`id_sucursal` INT DEFAULT 1,KEY `idx_kardex_prod` (`id_producto`),KEY `idx_kardex_fecha` (`fecha`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m004_ventas','module'=>'Core POS','icon'=>'fa-shopping-cart','description'=>'ventas_cabecera, ventas_detalle y ventas_pagos',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `ventas_cabecera` (`id` INT AUTO_INCREMENT PRIMARY KEY,`uuid_venta` VARCHAR(80) DEFAULT NULL,`fecha` DATETIME DEFAULT current_timestamp(),`total` DECIMAL(12,2) DEFAULT 0,`metodo_pago` VARCHAR(50) DEFAULT 'Efectivo',`precio_tipo` VARCHAR(20) DEFAULT 'normal',`descuento_orden` DECIMAL(5,2) DEFAULT 0,`id_sucursal` INT DEFAULT 1,`created_at` TIMESTAMP DEFAULT current_timestamp(),`id_empresa` INT DEFAULT 1,`id_almacen` INT DEFAULT 1,`id_caja` INT DEFAULT NULL,`tipo_servicio` VARCHAR(50) DEFAULT 'mostrador',`fecha_reserva` DATETIME DEFAULT NULL,`notas` TEXT DEFAULT NULL,`cliente_nombre` VARCHAR(150) DEFAULT NULL,`cliente_direccion` VARCHAR(255) DEFAULT NULL,`cliente_telefono` VARCHAR(50) DEFAULT NULL,`mensajero_nombre` VARCHAR(100) DEFAULT NULL,`abono_reserva` DECIMAL(12,2) DEFAULT 0,`id_sesion_caja` INT DEFAULT NULL,`abono` DECIMAL(12,2) DEFAULT 0,`codigo_pago` VARCHAR(100) DEFAULT NULL,`estado_pago` VARCHAR(30) DEFAULT 'pagado',`sin_existencia` TINYINT(1) DEFAULT 0,`estado_reserva` VARCHAR(20) DEFAULT NULL,`uuid` VARCHAR(80) DEFAULT NULL,`id_cliente` INT DEFAULT NULL,`sincronizado` TINYINT(1) DEFAULT 0,`canal_origen` VARCHAR(30) DEFAULT NULL,`motivo_rechazo` VARCHAR(500) DEFAULT NULL,`moneda` VARCHAR(10) DEFAULT 'CUP',`tipo_cambio` DECIMAL(10,4) DEFAULT 1.0000,`monto_moneda_original` DECIMAL(12,2) DEFAULT NULL,`motivo_anulacion` TEXT DEFAULT NULL,`anulada_por` VARCHAR(100) DEFAULT NULL,`anulada_en` DATETIME DEFAULT NULL,KEY `idx_vcab_fecha` (`fecha`),KEY `idx_vcab_sesion` (`id_sesion_caja`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `ventas_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_venta_cabecera` INT NOT NULL,`cantidad` DECIMAL(10,3) DEFAULT 1,`precio` DECIMAL(12,2) DEFAULT 0,`nombre_producto` VARCHAR(200) DEFAULT NULL,`codigo_producto` VARCHAR(50) DEFAULT NULL,`categoria_producto` VARCHAR(100) DEFAULT NULL,`descuento_pct` DECIMAL(5,2) DEFAULT 0,`descuento_monto` DECIMAL(12,2) DEFAULT 0,`reembolsado` TINYINT(1) DEFAULT 0,`id_producto` VARCHAR(50) DEFAULT NULL,KEY `idx_vdet_cab` (`id_venta_cabecera`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `ventas_pagos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_venta_cabecera` INT NOT NULL,`metodo_pago` VARCHAR(50) NOT NULL,`monto` DECIMAL(12,2) NOT NULL,KEY `idx_vpag_cab` (`id_venta_cabecera`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m005_compras','module'=>'Core POS','icon'=>'fa-truck','description'=>'compras_cabecera y compras_detalle',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `compras_cabecera` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATETIME DEFAULT current_timestamp(),`proveedor` VARCHAR(200) DEFAULT NULL,`total` DECIMAL(12,2) DEFAULT 0,`total_original` DECIMAL(12,2) DEFAULT NULL,`usuario` VARCHAR(100) DEFAULT NULL,`created_by` VARCHAR(100) DEFAULT NULL,`notas` TEXT DEFAULT NULL,`estado` VARCHAR(30) DEFAULT 'APLICADA',`id_empresa` INT DEFAULT 1,`id_sucursal` INT DEFAULT 1,`id_almacen` INT DEFAULT 1,`duplicated_from_id` INT DEFAULT NULL,`factura_adjunto` VARCHAR(255) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT current_timestamp(),`updated_at` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp(),`numero_factura` VARCHAR(100) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `compras_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_compra` INT NOT NULL,`id_producto` VARCHAR(50) DEFAULT NULL,`cantidad` DECIMAL(10,3) DEFAULT 1,`costo_unitario` DECIMAL(12,2) DEFAULT 0,`subtotal` DECIMAL(12,2) DEFAULT 0,`costo_anterior` DECIMAL(12,2) DEFAULT NULL,`costo_resultante` DECIMAL(12,2) DEFAULT NULL,`stock_antes` DECIMAL(12,3) DEFAULT NULL,`stock_despues` DECIMAL(12,3) DEFAULT NULL,`estado_item` VARCHAR(20) DEFAULT 'activo',`revertido_at` DATETIME DEFAULT NULL,`revertido_by` VARCHAR(100) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT current_timestamp(),KEY `idx_cdet_compra` (`id_compra`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m006_caja_cajeros','module'=>'Core POS','icon'=>'fa-cash-register','description'=>'caja_sesiones y pos_cashiers',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `caja_sesiones` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_usuario` INT DEFAULT NULL,`nombre_cajero` VARCHAR(100) DEFAULT NULL,`fecha_apertura` DATETIME DEFAULT current_timestamp(),`fecha_cierre` DATETIME DEFAULT NULL,`monto_inicial` DECIMAL(12,2) DEFAULT 0,`monto_final_sistema` DECIMAL(12,2) DEFAULT 0,`monto_final_real` DECIMAL(12,2) DEFAULT NULL,`diferencia` DECIMAL(12,2) DEFAULT 0,`estado` VARCHAR(20) DEFAULT 'abierta',`fecha_contable` DATE DEFAULT NULL,`id_sucursal` INT DEFAULT 1,`nota` TEXT DEFAULT NULL,KEY `idx_caja_estado` (`estado`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `pos_cashiers` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(100) NOT NULL,`pin` VARCHAR(10) NOT NULL,`rol` VARCHAR(20) NOT NULL DEFAULT 'cajero',`id_empresa` INT NOT NULL,`id_sucursal` INT NOT NULL,`id_almacen` INT NOT NULL,`activo` TINYINT(1) NOT NULL DEFAULT 1,`created_at` TIMESTAMP DEFAULT current_timestamp(),`updated_at` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp(),UNIQUE KEY `uniq_pin` (`pin`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

// ── MÓDULO: Operaciones POS ───────────────────────────────────────────────────
['id'=>'m010_mermas_entradas','module'=>'Operaciones POS','icon'=>'fa-exchange-alt','description'=>'mermas, entradas y transferencias entre almacenes',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `mermas_cabecera` (`id` INT AUTO_INCREMENT PRIMARY KEY,`usuario` VARCHAR(100),`motivo_general` TEXT,`total_costo_perdida` DECIMAL(12,2) DEFAULT 0,`estado` VARCHAR(20) DEFAULT 'PROCESADA',`id_sucursal` INT DEFAULT 1,`id_empresa` INT DEFAULT 1,`id_almacen` INT DEFAULT 1,`fecha_registro` DATETIME DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `mermas_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_merma` INT,`id_producto` VARCHAR(50),`cantidad` DECIMAL(10,3),`costo_al_momento` DECIMAL(12,2),`motivo_especifico` TEXT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `entradas_cabecera` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATETIME DEFAULT current_timestamp(),`usuario` VARCHAR(100),`motivo` TEXT,`total_costo` DECIMAL(12,2) DEFAULT 0,`estado` VARCHAR(20) DEFAULT 'APLICADA',`id_sucursal` INT DEFAULT 1,`id_empresa` INT DEFAULT 1,`id_almacen` INT DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `entradas_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_entrada` INT,`id_producto` VARCHAR(50),`cantidad` DECIMAL(10,3),`costo_unitario` DECIMAL(12,2),`subtotal` DECIMAL(12,2)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `transferencias_cabecera` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATETIME DEFAULT current_timestamp(),`usuario` VARCHAR(100),`id_almacen_origen` INT,`id_almacen_destino` INT,`estado` VARCHAR(20) DEFAULT 'APLICADA',`notas` TEXT,`id_sucursal` INT DEFAULT 1,`id_empresa` INT DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `transferencias_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_transferencia` INT,`id_producto` VARCHAR(50),`cantidad` DECIMAL(10,3)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m011_mesas_pisos_ordenes','module'=>'Operaciones POS','icon'=>'fa-utensils','description'=>'mesas, pisos, órdenes paralelas, pedidos en espera y comandas',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `pisos` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(50) NOT NULL,`orden` INT DEFAULT 0,`activo` TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `mesas` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(50) NOT NULL,`id_piso` INT UNSIGNED DEFAULT NULL,`capacidad` INT DEFAULT 4,`pos_x` DECIMAL(6,2) DEFAULT 50.00,`pos_y` DECIMAL(6,2) DEFAULT 50.00,`estado` ENUM('libre','ocupada','reservada') DEFAULT 'libre',`id_orden_actual` INT UNSIGNED DEFAULT NULL,`forma` VARCHAR(20) DEFAULT 'circulo',`activo` TINYINT(1) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `ordenes_paralelas` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`id_sesion_caja` INT DEFAULT NULL,`id_mesa` INT UNSIGNED DEFAULT NULL,`items_json` LONGTEXT NOT NULL,`estado` ENUM('activa','cerrada','cancelada') DEFAULT 'activa',`tipo_servicio` VARCHAR(30) DEFAULT 'Mesa',`cliente_nombre` VARCHAR(200) DEFAULT 'Mostrador',`precio_tipo` VARCHAR(20) DEFAULT 'normal',`descuento_orden` DECIMAL(5,2) DEFAULT 0,`notas` TEXT DEFAULT NULL,`created_at` TIMESTAMP DEFAULT current_timestamp(),`updated_at` TIMESTAMP DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `pedidos_espera` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre_referencia` VARCHAR(100) DEFAULT NULL,`datos_json` LONGTEXT DEFAULT NULL,`fecha` DATETIME DEFAULT current_timestamp(),`cajero` VARCHAR(100) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `comandas` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_venta` INT DEFAULT NULL,`id_mesa` INT UNSIGNED DEFAULT NULL,`items_json` LONGTEXT NOT NULL,`estado` VARCHAR(20) DEFAULT 'pendiente',`cajero` VARCHAR(100) DEFAULT NULL,`notas` TEXT DEFAULT NULL,`created_at` DATETIME DEFAULT current_timestamp(),`updated_at` DATETIME DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m012_pedidos','module'=>'Operaciones POS','icon'=>'fa-clipboard-list','description'=>'pedidos_cabecera y pedidos_detalle',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `pedidos_cabecera` (`id` INT AUTO_INCREMENT PRIMARY KEY,`cliente_nombre` VARCHAR(150) DEFAULT NULL,`cliente_direccion` VARCHAR(255) DEFAULT NULL,`cliente_telefono` VARCHAR(50) DEFAULT NULL,`cliente_email` VARCHAR(100) DEFAULT NULL,`cliente_contacto_pref` VARCHAR(30) DEFAULT NULL,`total` DECIMAL(12,2) DEFAULT 0,`estado` VARCHAR(30) DEFAULT 'nuevo',`fecha` DATETIME DEFAULT current_timestamp(),`fecha_programada` DATETIME DEFAULT NULL,`notas` TEXT DEFAULT NULL,`notas_admin` TEXT DEFAULT NULL,`id_empresa` INT DEFAULT 1,`id_sucursal` INT DEFAULT 1,`fecha_actualizacion` DATETIME DEFAULT current_timestamp() ON UPDATE current_timestamp(),KEY `idx_ped_estado` (`estado`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `pedidos_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_pedido` INT NOT NULL,`cantidad` DECIMAL(10,3) DEFAULT 1,`precio_unitario` DECIMAL(12,2) DEFAULT 0,`id_producto` VARCHAR(50) DEFAULT NULL,KEY `idx_peddet_pedido` (`id_pedido`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m013_templates_importacion','module'=>'Operaciones POS','icon'=>'fa-file-import','description'=>'pos_order_templates y módulo de importación masiva de compras',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `pos_order_templates` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(120) NOT NULL,`cliente_nombre` VARCHAR(120) DEFAULT NULL,`tipo_servicio` VARCHAR(50) DEFAULT 'mostrador',`mensajero_nombre` VARCHAR(100) DEFAULT NULL,`delivery_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00,`source_sale_id` INT DEFAULT NULL,`items_json` LONGTEXT NOT NULL,`created_by` VARCHAR(100) DEFAULT NULL,`id_empresa` INT NOT NULL DEFAULT 1,`id_sucursal` INT NOT NULL DEFAULT 1,`id_almacen` INT NOT NULL DEFAULT 1,`activo` TINYINT(1) NOT NULL DEFAULT 1,`created_at` TIMESTAMP DEFAULT current_timestamp(),`updated_at` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"ALTER TABLE `pos_order_templates` ADD COLUMN IF NOT EXISTS `delivery_cost` DECIMAL(12,2) NOT NULL DEFAULT 0.00",
"CREATE TABLE IF NOT EXISTS `purchase_import_runs` (`id` INT AUTO_INCREMENT PRIMARY KEY,`file_name` VARCHAR(255) NOT NULL,`user_ref` VARCHAR(100) NOT NULL,`existing_mode` VARCHAR(20) NOT NULL DEFAULT 'full',`total_rows` INT NOT NULL DEFAULT 0,`created_products` INT NOT NULL DEFAULT 0,`updated_products` INT NOT NULL DEFAULT 0,`status` VARCHAR(20) NOT NULL DEFAULT 'APLICADA',`purchases_json` LONGTEXT DEFAULT NULL,`created_at` DATETIME NOT NULL,`reverted_at` DATETIME DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `purchase_import_run_rows` (`id` INT AUTO_INCREMENT PRIMARY KEY,`run_id` INT NOT NULL,`line_no` INT NOT NULL,`empresa_id` INT NOT NULL,`sucursal_id` INT NOT NULL,`almacen_id` INT NOT NULL,`source_sku` VARCHAR(50) DEFAULT NULL,`target_sku` VARCHAR(50) NOT NULL,`resolution_mode` VARCHAR(30) NOT NULL,`product_action` VARCHAR(20) NOT NULL,`purchase_id` INT NOT NULL,`quantity` DECIMAL(15,4) NOT NULL DEFAULT 0,`cost_price` DECIMAL(15,2) NOT NULL DEFAULT 0,`previous_product_json` LONGTEXT DEFAULT NULL,`new_product_json` LONGTEXT DEFAULT NULL,`created_at` DATETIME NOT NULL,KEY `idx_run_id` (`run_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
]],

// ── MÓDULO: CRM / Clientes ────────────────────────────────────────────────────
['id'=>'m020_clientes_core','module'=>'CRM / Clientes','icon'=>'fa-users','description'=>'clientes con todos los campos CRM y mensajeros',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `clientes` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(150) NOT NULL,`telefono` VARCHAR(50) DEFAULT NULL,`direccion` VARCHAR(255) DEFAULT NULL,`activo` TINYINT(1) DEFAULT 1,`tipo_cliente` VARCHAR(30) DEFAULT 'Minorista',`uuid` VARCHAR(80) DEFAULT NULL,`fecha_registro` DATETIME DEFAULT current_timestamp(),`categoria` ENUM('Regular','VIP','Corporativo','Moroso','Empleado') DEFAULT 'Regular',`origen` VARCHAR(50) DEFAULT 'Local',`notas` TEXT DEFAULT NULL,`fecha_nacimiento` DATE DEFAULT NULL,`nit_ci` VARCHAR(50) DEFAULT NULL,`ruc` VARCHAR(50) DEFAULT NULL,`contacto_principal` VARCHAR(100) DEFAULT NULL,`giro_negocio` VARCHAR(100) DEFAULT NULL,`telefono_principal` VARCHAR(50) DEFAULT NULL,`direccion_principal` VARCHAR(255) DEFAULT NULL,`email` VARCHAR(100) DEFAULT NULL,`es_mensajero` TINYINT(1) DEFAULT 0,`vehiculo` VARCHAR(50) DEFAULT NULL,`matricula` VARCHAR(20) DEFAULT NULL,`preferencias` TEXT DEFAULT NULL,`updated_at` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp(),KEY `idx_cli_nombre` (`nombre`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `es_mensajero` TINYINT(1) DEFAULT 0",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `vehiculo` VARCHAR(50) DEFAULT NULL",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `matricula` VARCHAR(20) DEFAULT NULL",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `categoria` ENUM('Regular','VIP','Corporativo','Moroso','Empleado') DEFAULT 'Regular'",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `origen` VARCHAR(50) DEFAULT 'Local'",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `notas` TEXT DEFAULT NULL",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `fecha_nacimiento` DATE DEFAULT NULL",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `nit_ci` VARCHAR(50) DEFAULT NULL",
"ALTER TABLE `clientes` ADD COLUMN IF NOT EXISTS `email` VARCHAR(100) DEFAULT NULL",
]],

['id'=>'m021_clientes_tienda_crm','module'=>'CRM / Clientes','icon'=>'fa-id-card','description'=>'clientes_tienda, direcciones, teléfonos, usuarios, wishlist y sesiones',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `clientes_tienda` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(150),`email` VARCHAR(100),`telefono` VARCHAR(50),`direccion` VARCHAR(255),`password_hash` VARCHAR(255),`activo` TINYINT(1) DEFAULT 1,`fecha_registro` DATETIME DEFAULT current_timestamp(),UNIQUE KEY `uk_ctienda_email` (`email`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `clientes_direcciones` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_cliente` INT NOT NULL,`alias` VARCHAR(50) DEFAULT 'Casa',`direccion` VARCHAR(255) NOT NULL,`referencia` TEXT DEFAULT NULL,`activo` TINYINT(1) DEFAULT 1,`predeterminada` TINYINT(1) DEFAULT 0,KEY `idx_cdir_cliente` (`id_cliente`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `clientes_telefonos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_cliente` INT NOT NULL,`numero` VARCHAR(50) NOT NULL,`tipo` VARCHAR(30) DEFAULT 'celular',`activo` TINYINT(1) DEFAULT 1,KEY `idx_ctel_cliente` (`id_cliente`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `usuarios_clientes` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_cliente` INT DEFAULT NULL,`username` VARCHAR(80) NOT NULL,`email` VARCHAR(100) DEFAULT NULL,`password_hash` VARCHAR(255) NOT NULL,`activo` TINYINT(1) DEFAULT 1,`created_at` DATETIME DEFAULT current_timestamp(),UNIQUE KEY `uk_ucli_username` (`username`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `wishlist` (`id` INT AUTO_INCREMENT PRIMARY KEY,`cliente_id` INT NOT NULL,`producto_codigo` VARCHAR(50) NOT NULL,`fecha` DATETIME DEFAULT current_timestamp(),UNIQUE KEY `uk_wish` (`cliente_id`,`producto_codigo`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `sessions` (`id` VARCHAR(255) NOT NULL PRIMARY KEY,`user_id` BIGINT UNSIGNED DEFAULT NULL,`ip_address` VARCHAR(45) DEFAULT NULL,`user_agent` TEXT DEFAULT NULL,`payload` LONGTEXT NOT NULL,`last_activity` INT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `password_reset_tokens` (`email` VARCHAR(255) NOT NULL PRIMARY KEY,`token` VARCHAR(255) NOT NULL,`created_at` TIMESTAMP DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

// ── MÓDULO: Tienda Online ─────────────────────────────────────────────────────
['id'=>'m030_tienda_catalogo','module'=>'Tienda Online','icon'=>'fa-store','description'=>'variantes, combos, carritos, reseñas, restock, vistas y ofertas',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `producto_variantes` (`id` INT AUTO_INCREMENT PRIMARY KEY,`producto_codigo` VARCHAR(50) NOT NULL,`nombre_variante` VARCHAR(100) DEFAULT NULL,`sku_variante` VARCHAR(80) DEFAULT NULL,`precio_extra` DECIMAL(12,2) DEFAULT 0,`activo` TINYINT(1) DEFAULT 1,KEY `idx_pvar_prod` (`producto_codigo`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `producto_combo_items` (`id` INT AUTO_INCREMENT PRIMARY KEY,`combo_codigo` VARCHAR(50) NOT NULL,`componente_codigo` VARCHAR(50) NOT NULL,`cantidad` DECIMAL(12,3) NOT NULL DEFAULT 1.000,`orden` INT NOT NULL DEFAULT 0,`created_at` TIMESTAMP DEFAULT current_timestamp(),UNIQUE KEY `uniq_combo_comp` (`combo_codigo`,`componente_codigo`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `carritos_abandonados` (`id` INT AUTO_INCREMENT PRIMARY KEY,`session_id` VARCHAR(100),`cliente_id` INT DEFAULT NULL,`items_json` LONGTEXT,`fecha` DATETIME DEFAULT current_timestamp(),`recuperado` TINYINT(1) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `resenas_productos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`producto_codigo` VARCHAR(50) NOT NULL,`cliente_id` INT DEFAULT NULL,`nombre_cliente` VARCHAR(100) DEFAULT NULL,`estrellas` TINYINT DEFAULT 5,`comentario` TEXT DEFAULT NULL,`aprobada` TINYINT(1) DEFAULT 0,`fecha` DATETIME DEFAULT current_timestamp(),KEY `idx_res_prod` (`producto_codigo`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `restock_avisos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`producto_codigo` VARCHAR(50) NOT NULL,`email` VARCHAR(100) DEFAULT NULL,`telefono` VARCHAR(50) DEFAULT NULL,`fecha` DATETIME DEFAULT current_timestamp(),`notificado` TINYINT(1) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `vistas_productos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`producto_codigo` VARCHAR(50) NOT NULL,`ip` VARCHAR(45) DEFAULT NULL,`session_id` VARCHAR(100) DEFAULT NULL,`fecha` DATETIME DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `ofertas` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(150) NOT NULL,`descripcion` TEXT DEFAULT NULL,`descuento_pct` DECIMAL(5,2) DEFAULT 0,`activa` TINYINT(1) DEFAULT 1,`fecha_inicio` DATETIME DEFAULT NULL,`fecha_fin` DATETIME DEFAULT NULL,`id_empresa` INT DEFAULT 1,`created_at` DATETIME DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `ofertas_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_oferta` INT NOT NULL,`producto_codigo` VARCHAR(50) NOT NULL,KEY `idx_ofdet_oferta` (`id_oferta`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m031_tienda_metricas','module'=>'Tienda Online','icon'=>'fa-chart-bar','description'=>'métricas web, web_stats, horarios tienda y precios por sucursal',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `metricas_web` (`id` INT AUTO_INCREMENT PRIMARY KEY,`ip` VARCHAR(45) DEFAULT NULL,`pais` VARCHAR(50) DEFAULT 'Desconocido',`url_visitada` VARCHAR(255) DEFAULT NULL,`user_agent` TEXT DEFAULT NULL,`fecha` DATETIME DEFAULT current_timestamp(),`session_id` VARCHAR(100) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `web_stats` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` TIMESTAMP DEFAULT current_timestamp(),`ip` VARCHAR(45) DEFAULT NULL,`pagina` VARCHAR(255) DEFAULT NULL,`user_agent` TEXT DEFAULT NULL,`session_id` VARCHAR(100) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `horarios_tienda` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,`dia_semana` TINYINT NOT NULL,`hora_apertura` TIME NOT NULL DEFAULT '09:00:00',`hora_cierre` TIME NOT NULL DEFAULT '22:00:00',`activo` TINYINT(1) DEFAULT 1,`id_sucursal` INT DEFAULT NULL,UNIQUE KEY `uq_dia_suc` (`dia_semana`,`id_sucursal`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `productos_precios_sucursal` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_sucursal` INT NOT NULL,`codigo_producto` VARCHAR(50) NOT NULL,`precio` DECIMAL(12,2) NOT NULL DEFAULT 0,`precio_mayorista` DECIMAL(12,2) DEFAULT NULL,`updated_at` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp(),UNIQUE KEY `uk_suc_prod` (`id_sucursal`,`codigo_producto`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

// ── MÓDULO: Contabilidad ──────────────────────────────────────────────────────
['id'=>'m040_contabilidad_plan','module'=>'Contabilidad','icon'=>'fa-calculator','description'=>'plan de cuentas, diario contable, gastos y configuración',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `contabilidad_cuentas` (`id` INT AUTO_INCREMENT PRIMARY KEY,`codigo` VARCHAR(20) NOT NULL,`nombre` VARCHAR(150) NOT NULL,`tipo` VARCHAR(30) DEFAULT NULL,`nivel` INT DEFAULT 1,`cuenta_padre` VARCHAR(20) DEFAULT NULL,`activa` TINYINT(1) DEFAULT 1,UNIQUE KEY `uk_cuenta_cod` (`codigo`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `contabilidad_diario` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATE NOT NULL,`descripcion` TEXT DEFAULT NULL,`cuenta_debito` VARCHAR(20) DEFAULT NULL,`cuenta_credito` VARCHAR(20) DEFAULT NULL,`monto` DECIMAL(14,2) NOT NULL DEFAULT 0,`referencia` VARCHAR(100) DEFAULT NULL,`usuario` VARCHAR(100) DEFAULT NULL,`created_at` DATETIME DEFAULT current_timestamp(),KEY `idx_cont_fecha` (`fecha`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `contabilidad_gastos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATE NOT NULL,`concepto` VARCHAR(200) NOT NULL,`monto` DECIMAL(12,2) NOT NULL DEFAULT 0,`categoria` VARCHAR(80) DEFAULT NULL,`usuario` VARCHAR(100) DEFAULT NULL,`created_at` DATETIME DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `contabilidad_config` (`clave` VARCHAR(50) NOT NULL PRIMARY KEY,`valor` VARCHAR(255) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `contabilidad_saldos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATE DEFAULT NULL,`caja_fuerte` DECIMAL(12,2) DEFAULT 0,`banco` DECIMAL(12,2) DEFAULT 0,`observaciones` TEXT DEFAULT NULL,UNIQUE KEY `uk_saldo_fecha` (`fecha`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m041_gastos_facturas','module'=>'Contabilidad','icon'=>'fa-file-invoice-dollar','description'=>'gastos fijos/historial/plantillas, facturas, config_facturacion y ONAT',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `gastos_fijos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`concepto` VARCHAR(150) NOT NULL,`monto_estimado` DECIMAL(10,2) DEFAULT 0,`dia_pago_sugerido` INT DEFAULT 1,`categoria` VARCHAR(50) DEFAULT 'General',`activo` TINYINT(4) DEFAULT 1) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `gastos_historial` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATE NOT NULL,`concepto` VARCHAR(150) DEFAULT NULL,`monto` DECIMAL(10,2) NOT NULL,`categoria` VARCHAR(50) DEFAULT NULL,`id_usuario` VARCHAR(50) DEFAULT NULL,`notas` TEXT DEFAULT NULL,`tipo` ENUM('VARIABLE','FIJO') DEFAULT 'VARIABLE',`id_sucursal` INT NOT NULL DEFAULT 0,KEY `idx_gastos_suc` (`id_sucursal`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `gastos_plantillas_operativos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(100) NOT NULL,`categoria` VARCHAR(50) NOT NULL DEFAULT 'OPERATIVO',`monto_default` DECIMAL(12,2) DEFAULT 0,`descripcion` TEXT DEFAULT NULL,`activo` TINYINT(1) DEFAULT 1,`orden` INT DEFAULT 0,`es_salario` TINYINT(1) DEFAULT 0,`tipo_calculo_salario` ENUM('FIJO','PORCENTAJE_VENTAS','FIJO_MAS_PORCENTAJE','FIJO_MAS_PORCENTAJE_SOBRE_META','PORCENTAJE_ESCALONADO','POR_HORA','PERSONALIZADO') DEFAULT 'FIJO',`salario_fijo` DECIMAL(12,2) DEFAULT 0,`porcentaje_ventas` DECIMAL(5,2) DEFAULT 0,`meta_ventas` DECIMAL(12,2) DEFAULT 0,`porcentaje_sobre_meta` DECIMAL(5,2) DEFAULT 0,`valor_hora` DECIMAL(10,2) DEFAULT 0,`config_escalonado` LONGTEXT DEFAULT NULL,`created_at` TIMESTAMP DEFAULT current_timestamp(),`updated_at` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `facturas` (`id` INT AUTO_INCREMENT PRIMARY KEY,`numero_factura` VARCHAR(50) DEFAULT NULL,`fecha_emision` DATETIME DEFAULT current_timestamp(),`id_ticket_origen` INT DEFAULT NULL,`cliente_nombre` VARCHAR(150) DEFAULT NULL,`cliente_direccion` VARCHAR(255) DEFAULT NULL,`cliente_telefono` VARCHAR(50) DEFAULT NULL,`mensajero_nombre` VARCHAR(100) DEFAULT NULL,`vehiculo` VARCHAR(80) DEFAULT NULL,`subtotal` DECIMAL(12,2) DEFAULT 0,`total` DECIMAL(12,2) DEFAULT 0,`creado_por` VARCHAR(100) DEFAULT NULL,`estado` VARCHAR(20) DEFAULT 'vigente',`estado_pago` VARCHAR(20) DEFAULT 'pendiente',`metodo_pago` VARCHAR(50) DEFAULT NULL,`fecha_pago` DATETIME DEFAULT NULL,`costo_envio` DECIMAL(12,2) DEFAULT 0,`notas` TEXT DEFAULT NULL,`tipo` VARCHAR(20) DEFAULT 'venta') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `facturas_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_factura` INT NOT NULL,`descripcion` VARCHAR(255) DEFAULT NULL,`unidad_medida` VARCHAR(20) DEFAULT 'und',`cantidad` DECIMAL(10,3) DEFAULT 1,`precio_unitario` DECIMAL(12,2) DEFAULT 0,`importe` DECIMAL(12,2) DEFAULT 0,KEY `idx_fdet_factura` (`id_factura`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `config_facturacion` (`id` INT AUTO_INCREMENT PRIMARY KEY,`ultimo_consecutivo` INT DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `declaraciones_onat` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_empresa` INT NOT NULL,`anio` INT NOT NULL,`mes` INT NOT NULL,`tipo_entidad` VARCHAR(20) DEFAULT NULL,`ingresos_brutos` DECIMAL(12,2) DEFAULT NULL,`costo_ventas` DECIMAL(12,2) DEFAULT NULL,`gastos_nomina` DECIMAL(12,2) DEFAULT NULL,`gastos_otros` DECIMAL(12,2) DEFAULT NULL,`imp_ventas` DECIMAL(12,2) DEFAULT NULL,`imp_fuerza` DECIMAL(12,2) DEFAULT NULL,`contrib_local` DECIMAL(12,2) DEFAULT NULL,`seg_social` DECIMAL(12,2) DEFAULT NULL,`seg_social_especial` DECIMAL(12,2) DEFAULT NULL,`imp_utilidades` DECIMAL(12,2) DEFAULT NULL,`total_pagar` DECIMAL(12,2) DEFAULT NULL,`fecha_registro` DATETIME DEFAULT current_timestamp(),UNIQUE KEY `uk_decl` (`id_empresa`,`anio`,`mes`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

['id'=>'m042_reportes_flujo','module'=>'Contabilidad','icon'=>'fa-chart-line','description'=>'reportes_cierre y flujo_caja_mensual',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `reportes_cierre` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_sucursal` INT NOT NULL,`fecha_inicio` DATE NOT NULL,`fecha_fin` DATE NOT NULL,`venta_total` DECIMAL(10,2) DEFAULT 0,`ganancia_neta` DECIMAL(10,2) DEFAULT 0,`datos_json` TEXT DEFAULT NULL,`fecha_creacion` TIMESTAMP DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `flujo_caja_mensual` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATE NOT NULL,`concepto_key` VARCHAR(50) NOT NULL,`valor` TEXT DEFAULT NULL,`id_sucursal` INT DEFAULT 1,`updated_at` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp(),UNIQUE KEY `uk_celda` (`fecha`,`concepto_key`,`id_sucursal`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

// ── MÓDULO: Recetas y Producción ──────────────────────────────────────────────
['id'=>'m050_recetas_produccion','module'=>'Recetas / Producción','icon'=>'fa-flask','description'=>'recetas_cabecera, recetas_detalle (con pct_formula) y producciones_historial',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `recetas_cabecera` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(150) NOT NULL,`id_producto_final` VARCHAR(50) DEFAULT NULL,`unidades_por_lote` DECIMAL(12,3) DEFAULT 1,`costo_estimado` DECIMAL(12,2) DEFAULT 0,`activa` TINYINT(1) DEFAULT 1,`notas` TEXT DEFAULT NULL,`created_at` DATETIME DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `recetas_detalle` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_receta` INT NOT NULL,`id_producto` VARCHAR(50) NOT NULL,`cantidad` DECIMAL(12,3) NOT NULL DEFAULT 1,`pct_formula` DECIMAL(5,2) DEFAULT 0,KEY `idx_rdet_receta` (`id_receta`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"ALTER TABLE `recetas_detalle` ADD COLUMN IF NOT EXISTS `pct_formula` DECIMAL(5,2) DEFAULT 0",
"CREATE TABLE IF NOT EXISTS `producciones_historial` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_receta` INT DEFAULT NULL,`nombre_receta` VARCHAR(150) DEFAULT NULL,`id_producto_final` VARCHAR(50) DEFAULT NULL,`cantidad_lotes` DECIMAL(10,2) DEFAULT NULL,`unidades_creadas` DECIMAL(12,2) DEFAULT NULL,`fecha` DATETIME DEFAULT current_timestamp(),`usuario` VARCHAR(50) DEFAULT NULL,`costo_total` DECIMAL(12,2) DEFAULT NULL,`revertido` TINYINT(4) DEFAULT 0,`json_snapshot` LONGTEXT DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

// ── MÓDULO: Bots y Notificaciones ─────────────────────────────────────────────
['id'=>'m060_whatsapp_bot','module'=>'Bots y Notificaciones','icon'=>'fa-comment-dots','description'=>'Bot WhatsApp: pos_bot_config, messages, orders, sessions y pos_user_contexts',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `pos_bot_config` (`id` TINYINT NOT NULL DEFAULT 1,`enabled` TINYINT(1) NOT NULL DEFAULT 0,`auto_schedule_enabled` TINYINT(1) NOT NULL DEFAULT 1,`auto_off_start` CHAR(5) NOT NULL DEFAULT '07:00',`auto_off_end` CHAR(5) NOT NULL DEFAULT '20:00',`wa_mode` ENUM('web','meta_api') NOT NULL DEFAULT 'web',`bot_tone` ENUM('premium','popular_cubano','formal_comercial','muy_cercano') NOT NULL DEFAULT 'muy_cercano',`verify_token` VARCHAR(120) DEFAULT NULL,`wa_phone_number_id` VARCHAR(80) DEFAULT NULL,`wa_access_token` TEXT DEFAULT NULL,`business_name` VARCHAR(120) DEFAULT 'PalWeb POS',`welcome_message` TEXT DEFAULT NULL,`menu_intro` TEXT DEFAULT NULL,`no_match_message` TEXT DEFAULT NULL,`updated_at` DATETIME DEFAULT current_timestamp() ON UPDATE current_timestamp(),PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `pos_bot_messages` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`wa_user_id` VARCHAR(40) NOT NULL,`direction` ENUM('in','out') NOT NULL,`msg_type` VARCHAR(20) DEFAULT 'text',`message_text` TEXT DEFAULT NULL,`created_at` DATETIME DEFAULT current_timestamp(),KEY `idx_wa_created` (`wa_user_id`,`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `pos_bot_orders` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`id_pedido` INT NOT NULL,`wa_user_id` VARCHAR(40) NOT NULL,`total` DECIMAL(10,2) NOT NULL DEFAULT 0,`created_at` DATETIME DEFAULT current_timestamp(),KEY `idx_bot_pedido` (`id_pedido`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `pos_bot_sessions` (`wa_user_id` VARCHAR(40) NOT NULL PRIMARY KEY,`wa_name` VARCHAR(120) DEFAULT NULL,`cart_json` TEXT DEFAULT NULL,`last_seen` DATETIME DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `pos_user_contexts` (`user_id` BIGINT UNSIGNED NOT NULL PRIMARY KEY,`id_empresa` INT NOT NULL,`id_sucursal` INT NOT NULL,`id_almacen` INT NOT NULL,`activo` TINYINT(1) NOT NULL DEFAULT 1,`created_at` TIMESTAMP DEFAULT current_timestamp(),`updated_at` TIMESTAMP DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
]],

['id'=>'m061_facebook_instagram_bot','module'=>'Bots y Notificaciones','icon'=>'fa-facebook','description'=>'Bot Facebook/Instagram con columnas de Instagram y tabla de posts',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `fb_bot_config` (`id` TINYINT NOT NULL DEFAULT 1,`enabled` TINYINT(1) NOT NULL DEFAULT 0,`business_name` VARCHAR(120) DEFAULT 'PalWeb Facebook',`page_name` VARCHAR(120) DEFAULT '',`page_id` VARCHAR(80) DEFAULT '',`page_access_token` TEXT DEFAULT NULL,`enable_instagram` TINYINT(1) NOT NULL DEFAULT 0,`ig_username` VARCHAR(120) DEFAULT '',`ig_user_id` VARCHAR(80) DEFAULT '',`ig_access_token` TEXT DEFAULT NULL,`graph_version` VARCHAR(20) DEFAULT 'v23.0',`worker_key` VARCHAR(120) DEFAULT 'palweb_fb_worker',`updated_at` DATETIME DEFAULT current_timestamp() ON UPDATE current_timestamp(),PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"ALTER TABLE `fb_bot_config` ADD COLUMN IF NOT EXISTS `enable_instagram` TINYINT(1) NOT NULL DEFAULT 0",
"ALTER TABLE `fb_bot_config` ADD COLUMN IF NOT EXISTS `ig_username` VARCHAR(120) DEFAULT ''",
"ALTER TABLE `fb_bot_config` ADD COLUMN IF NOT EXISTS `ig_user_id` VARCHAR(80) DEFAULT ''",
"ALTER TABLE `fb_bot_config` ADD COLUMN IF NOT EXISTS `ig_access_token` TEXT DEFAULT NULL",
"ALTER TABLE `fb_bot_config` ADD COLUMN IF NOT EXISTS `graph_version` VARCHAR(20) DEFAULT 'v23.0'",
"CREATE TABLE IF NOT EXISTS `fb_bot_posts` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`platform` ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook',`campaign_id` VARCHAR(120) DEFAULT '',`page_id` VARCHAR(80) DEFAULT '',`page_name` VARCHAR(120) DEFAULT '',`fb_post_id` VARCHAR(120) DEFAULT '',`message_text` MEDIUMTEXT DEFAULT NULL,`status` ENUM('success','error') NOT NULL DEFAULT 'success',`error_text` TEXT DEFAULT NULL,`created_at` DATETIME DEFAULT current_timestamp(),KEY `idx_fb_created` (`created_at`),KEY `idx_fb_platform` (`platform`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"ALTER TABLE `fb_bot_posts` ADD COLUMN IF NOT EXISTS `platform` ENUM('facebook','instagram') NOT NULL DEFAULT 'facebook'",
]],

['id'=>'m062_push_chat','module'=>'Bots y Notificaciones','icon'=>'fa-bell','description'=>'push_subscriptions, push_notifications y chat_messages',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `push_subscriptions` (`id` INT AUTO_INCREMENT PRIMARY KEY,`endpoint` TEXT NOT NULL,`p256dh` VARCHAR(500) NOT NULL,`auth` VARCHAR(200) NOT NULL,`tipo` VARCHAR(30) NOT NULL DEFAULT 'operador',`device_name` VARCHAR(150) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT current_timestamp(),`last_push` TIMESTAMP DEFAULT NULL,UNIQUE KEY `uk_push_ep` (`endpoint`(500))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `push_notifications` (`id` INT AUTO_INCREMENT PRIMARY KEY,`tipo` VARCHAR(30) NOT NULL,`event_key` VARCHAR(80) NOT NULL DEFAULT '',`titulo` VARCHAR(200) NOT NULL,`cuerpo` TEXT DEFAULT NULL,`url` VARCHAR(500) DEFAULT NULL,`acciones` TEXT DEFAULT NULL,`leida` TINYINT(1) DEFAULT 0,`created_at` TIMESTAMP DEFAULT current_timestamp(),KEY `idx_push_tipo` (`tipo`,`leida`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `chat_messages` (`id` INT AUTO_INCREMENT PRIMARY KEY,`usuario` VARCHAR(100) DEFAULT NULL,`mensaje` TEXT NOT NULL,`tipo` VARCHAR(20) DEFAULT 'texto',`fecha` DATETIME DEFAULT current_timestamp(),`leido` TINYINT(1) DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

// ── MÓDULO: Water Sync ────────────────────────────────────────────────────────
['id'=>'m070_water_sync','module'=>'Water Sync','icon'=>'fa-sync-alt','description'=>'water_sync_clients, events y access_log para sincronización multi-punto',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `water_sync_clients` (`id` INT AUTO_INCREMENT PRIMARY KEY,`device_name` VARCHAR(190) NOT NULL,`last_seen_at` DATETIME NOT NULL,`created_at` TIMESTAMP DEFAULT current_timestamp(),UNIQUE KEY `uk_ws_device` (`device_name`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `water_sync_events` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`client_id` INT NOT NULL,`local_event_id` BIGINT NOT NULL,`event_type` VARCHAR(60) NOT NULL,`payload_json` LONGTEXT NOT NULL,`created_at_ms` BIGINT NOT NULL,`received_at` TIMESTAMP DEFAULT current_timestamp(),UNIQUE KEY `uniq_client_event` (`client_id`,`local_event_id`),KEY `idx_client_received` (`client_id`,`received_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `water_sync_access_log` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`action` VARCHAR(32) NOT NULL,`client_ip` VARCHAR(120) NOT NULL,`user_agent` VARCHAR(255) DEFAULT NULL,`content_length` INT NOT NULL DEFAULT 0,`device_name` VARCHAR(190) DEFAULT NULL,`status_code` INT DEFAULT NULL,`received_count` INT DEFAULT NULL,`inserted_count` INT DEFAULT NULL,`discarded_count` INT DEFAULT NULL,`payload_sha256` VARCHAR(128) DEFAULT NULL,`created_at` TIMESTAMP DEFAULT current_timestamp(),KEY `idx_wsal_created` (`created_at`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
]],

// ── MÓDULO: Sistema / Auditoría ───────────────────────────────────────────────
['id'=>'m080_auditoria_sistema','module'=>'Sistema / Auditoría','icon'=>'fa-shield-alt','description'=>'auditoria_pos (ip/checksum), sync_journal y columnas de sucursales',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `auditoria_pos` (`id` INT AUTO_INCREMENT PRIMARY KEY,`fecha` DATETIME DEFAULT current_timestamp(),`usuario` VARCHAR(100) DEFAULT NULL,`accion` VARCHAR(100) DEFAULT NULL,`detalle` TEXT DEFAULT NULL,`ip` VARCHAR(45) DEFAULT NULL,`checksum` CHAR(40) DEFAULT NULL,KEY `idx_audit_fecha` (`fecha`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"ALTER TABLE `auditoria_pos` ADD COLUMN IF NOT EXISTS `ip` VARCHAR(45) NULL",
"ALTER TABLE `auditoria_pos` ADD COLUMN IF NOT EXISTS `checksum` CHAR(40) NULL",
"CREATE TABLE IF NOT EXISTS `sync_journal` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`tabla` VARCHAR(80) NOT NULL,`operacion` VARCHAR(20) NOT NULL,`registro_id` VARCHAR(100) DEFAULT NULL,`datos_json` LONGTEXT DEFAULT NULL,`aplicado` TINYINT(1) DEFAULT 0,`created_at` TIMESTAMP DEFAULT current_timestamp(),KEY `idx_syncj_tabla` (`tabla`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `sucursales` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(100) NOT NULL,`id_empresa` INT DEFAULT 1,`activo` TINYINT(1) DEFAULT 1,`direccion` VARCHAR(255) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"ALTER TABLE `sucursales` ADD COLUMN IF NOT EXISTS `banner_bg_size` VARCHAR(20) NOT NULL DEFAULT 'cover'",
"ALTER TABLE `sucursales` ADD COLUMN IF NOT EXISTS `banner_scale` TINYINT UNSIGNED NOT NULL DEFAULT 100",
]],

// ── MÓDULO: Módulo Afiliados ──────────────────────────────────────────────────
['id'=>'m090_affiliate_base','module'=>'Módulo Afiliados','icon'=>'fa-handshake','description'=>'affiliate_owners, gestores, users y products (tablas base sin FK)',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `affiliate_owners` (`id` INT AUTO_INCREMENT PRIMARY KEY,`owner_code` VARCHAR(20) NOT NULL,`owner_name` VARCHAR(120) NOT NULL,`phone` VARCHAR(40) DEFAULT NULL,`whatsapp_number` VARCHAR(40) DEFAULT NULL,`geo_zone` VARCHAR(120) DEFAULT NULL,`subscription_plan` VARCHAR(40) NOT NULL DEFAULT 'basic',`managed_service` TINYINT(1) NOT NULL DEFAULT 0,`reputation_score` DECIMAL(5,2) NOT NULL DEFAULT 80.00,`fraud_risk` VARCHAR(20) NOT NULL DEFAULT 'BAJO',`monthly_fee` DECIMAL(12,2) NOT NULL DEFAULT 0,`subscription_due_at` DATETIME DEFAULT NULL,`advertising_budget` DECIMAL(12,2) NOT NULL DEFAULT 0,`ads_active` TINYINT(1) NOT NULL DEFAULT 0,`available_balance` DECIMAL(12,2) NOT NULL DEFAULT 0,`blocked_balance` DECIMAL(12,2) NOT NULL DEFAULT 0,`total_balance` DECIMAL(12,2) NOT NULL DEFAULT 0,`status` ENUM('active','suspended') NOT NULL DEFAULT 'active',`created_at` DATETIME NOT NULL DEFAULT current_timestamp(),UNIQUE KEY `uk_owner_code` (`owner_code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_gestores` (`id` VARCHAR(20) NOT NULL PRIMARY KEY,`name` VARCHAR(120) NOT NULL,`phone` VARCHAR(40) DEFAULT NULL,`telegram_chat_id` VARCHAR(80) DEFAULT NULL,`masked_code` VARCHAR(60) DEFAULT NULL,`reputation_score` DECIMAL(5,2) NOT NULL DEFAULT 80,`earnings` DECIMAL(12,2) NOT NULL DEFAULT 0,`links` INT NOT NULL DEFAULT 0,`conversions` INT NOT NULL DEFAULT 0,`rating` DECIMAL(4,2) NOT NULL DEFAULT 0,`status` ENUM('active','suspended') NOT NULL DEFAULT 'active',`created_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_users` (`id` INT AUTO_INCREMENT PRIMARY KEY,`username` VARCHAR(80) NOT NULL,`password_hash` VARCHAR(255) NOT NULL,`role` ENUM('admin','owner','gestor') NOT NULL,`display_name` VARCHAR(120) NOT NULL,`owner_id` INT DEFAULT NULL,`gestor_id` VARCHAR(20) DEFAULT NULL,`status` ENUM('active','suspended') NOT NULL DEFAULT 'active',`created_at` DATETIME NOT NULL DEFAULT current_timestamp(),UNIQUE KEY `uk_aff_username` (`username`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_products` (`id` VARCHAR(20) NOT NULL PRIMARY KEY,`owner_id` INT NOT NULL,`name` VARCHAR(190) NOT NULL,`category` VARCHAR(80) NOT NULL,`price` DECIMAL(12,2) NOT NULL DEFAULT 0,`stock` INT NOT NULL DEFAULT 0,`commission` DECIMAL(12,2) NOT NULL DEFAULT 0,`commission_pct` DECIMAL(6,2) NOT NULL DEFAULT 0,`icon` VARCHAR(16) NOT NULL DEFAULT '?',`image_url` VARCHAR(255) DEFAULT NULL,`image_webp_url` VARCHAR(255) DEFAULT NULL,`image_thumb_url` VARCHAR(255) DEFAULT NULL,`brand` VARCHAR(80) NOT NULL DEFAULT 'Generic',`description` TEXT DEFAULT NULL,`clicks` INT NOT NULL DEFAULT 0,`leads` INT NOT NULL DEFAULT 0,`sales` INT NOT NULL DEFAULT 0,`trending` TINYINT(1) NOT NULL DEFAULT 0,`is_featured` TINYINT(1) NOT NULL DEFAULT 0,`sponsor_rank` INT NOT NULL DEFAULT 0,`price_mode` VARCHAR(20) NOT NULL DEFAULT 'fixed',`coupon_label` VARCHAR(120) DEFAULT NULL,`active` TINYINT(1) NOT NULL DEFAULT 1,`created_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
]],

['id'=>'m091_affiliate_leads_wallet','module'=>'Módulo Afiliados','icon'=>'fa-wallet','description'=>'affiliate_leads, trace_links, wallet_movements, topups, billing y pagos',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `affiliate_leads` (`id` VARCHAR(20) NOT NULL PRIMARY KEY,`owner_id` INT NOT NULL,`product_id` VARCHAR(20) NOT NULL,`gestor_id` VARCHAR(20) NOT NULL,`client` VARCHAR(80) NOT NULL,`client_name` VARCHAR(120) DEFAULT NULL,`client_phone` VARCHAR(40) DEFAULT NULL,`lead_date` DATE NOT NULL,`triggered_at` DATETIME DEFAULT NULL,`sold_at` DATETIME DEFAULT NULL,`no_sale_at` DATETIME DEFAULT NULL,`fraud_flag` TINYINT(1) NOT NULL DEFAULT 0,`status` VARCHAR(30) NOT NULL DEFAULT 'new',`commission` DECIMAL(12,2) NOT NULL DEFAULT 0,`locked_commission` DECIMAL(12,2) NOT NULL DEFAULT 0,`gestor_share` DECIMAL(12,2) NOT NULL DEFAULT 0,`platform_share` DECIMAL(12,2) NOT NULL DEFAULT 0,`trace_code` VARCHAR(40) NOT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_trace_links` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`product_id` VARCHAR(20) NOT NULL,`gestor_id` VARCHAR(20) NOT NULL,`owner_id` INT NOT NULL,`ref_token` VARCHAR(255) NOT NULL,`masked_ref` VARCHAR(64) NOT NULL,`clicks` INT NOT NULL DEFAULT 0,`contact_opens` INT NOT NULL DEFAULT 0,`last_opened_at` DATETIME DEFAULT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp(),UNIQUE KEY `uk_aff_ref_token` (`ref_token`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_wallet_movements` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`owner_id` INT NOT NULL,`lead_id` VARCHAR(20) DEFAULT NULL,`movement_type` VARCHAR(40) NOT NULL,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`delta_available` DECIMAL(12,2) NOT NULL DEFAULT 0,`delta_blocked` DECIMAL(12,2) NOT NULL DEFAULT 0,`available_before` DECIMAL(12,2) NOT NULL DEFAULT 0,`available_after` DECIMAL(12,2) NOT NULL DEFAULT 0,`blocked_before` DECIMAL(12,2) NOT NULL DEFAULT 0,`blocked_after` DECIMAL(12,2) NOT NULL DEFAULT 0,`note` VARCHAR(190) NOT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_wallet_topups` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`owner_id` INT NOT NULL,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`payment_method` VARCHAR(40) NOT NULL,`reference_code` VARCHAR(120) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'pending',`note` VARCHAR(255) DEFAULT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp(),`reviewed_at` DATETIME DEFAULT NULL,`reviewed_by` VARCHAR(80) DEFAULT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_billing_charges` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`owner_id` INT NOT NULL,`charge_type` VARCHAR(30) NOT NULL,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`reference_code` VARCHAR(120) NOT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'pending',`note` VARCHAR(255) DEFAULT NULL,`due_at` DATETIME DEFAULT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp(),`paid_at` DATETIME DEFAULT NULL,`settled_by` VARCHAR(80) DEFAULT NULL,UNIQUE KEY `uk_aff_charge_ref` (`reference_code`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_external_payments` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`owner_id` INT DEFAULT NULL,`payment_channel` VARCHAR(40) NOT NULL,`reference_code` VARCHAR(120) NOT NULL,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`payer_name` VARCHAR(120) DEFAULT NULL,`source_type` VARCHAR(20) NOT NULL DEFAULT 'extract',`status` VARCHAR(20) NOT NULL DEFAULT 'pending',`note` VARCHAR(255) DEFAULT NULL,`paid_at` DATETIME DEFAULT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_payment_reconciliations` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`owner_id` INT DEFAULT NULL,`payment_channel` VARCHAR(40) NOT NULL,`reference_code` VARCHAR(120) NOT NULL,`amount` DECIMAL(12,2) NOT NULL DEFAULT 0,`target_type` VARCHAR(30) NOT NULL,`target_id` BIGINT NOT NULL DEFAULT 0,`status` VARCHAR(20) NOT NULL DEFAULT 'matched',`note` VARCHAR(255) DEFAULT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
]],

['id'=>'m092_affiliate_sistema','module'=>'Módulo Afiliados','icon'=>'fa-cog','description'=>'affiliate_settings, login_attempts, user_sessions, webpush_subs y audit',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `affiliate_settings` (`setting_key` VARCHAR(80) NOT NULL PRIMARY KEY,`setting_value` TEXT DEFAULT NULL,`updated_at` DATETIME NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_login_attempts` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`username` VARCHAR(80) NOT NULL,`ip_address` VARCHAR(80) DEFAULT NULL,`user_agent` VARCHAR(255) DEFAULT NULL,`success` TINYINT(1) NOT NULL DEFAULT 0,`attempted_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_user_sessions` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`user_id` INT NOT NULL,`session_id` VARCHAR(128) NOT NULL,`role` VARCHAR(20) NOT NULL,`ip_address` VARCHAR(80) DEFAULT NULL,`user_agent` VARCHAR(255) DEFAULT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp(),`last_seen_at` DATETIME NOT NULL DEFAULT current_timestamp(),`expires_at` DATETIME NOT NULL,`logged_out_at` DATETIME DEFAULT NULL,`revoked_at` DATETIME DEFAULT NULL,UNIQUE KEY `uk_aff_session` (`session_id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_webpush_subscriptions` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`user_id` INT DEFAULT NULL,`role` VARCHAR(20) DEFAULT NULL,`endpoint` VARCHAR(255) NOT NULL,`p256dh_key` TEXT DEFAULT NULL,`auth_key` TEXT DEFAULT NULL,`user_agent` VARCHAR(255) DEFAULT NULL,`status` VARCHAR(20) NOT NULL DEFAULT 'active',`created_at` DATETIME NOT NULL DEFAULT current_timestamp(),`last_notified_at` DATETIME DEFAULT NULL,UNIQUE KEY `uk_aff_endpoint` (`endpoint`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_audit_events` (`id` BIGINT AUTO_INCREMENT PRIMARY KEY,`owner_id` INT DEFAULT NULL,`gestor_id` VARCHAR(20) DEFAULT NULL,`product_id` VARCHAR(20) DEFAULT NULL,`lead_id` VARCHAR(20) DEFAULT NULL,`event_type` VARCHAR(60) NOT NULL,`severity` VARCHAR(20) NOT NULL DEFAULT 'info',`message` VARCHAR(255) NOT NULL,`context_json` LONGTEXT DEFAULT NULL,`created_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
"CREATE TABLE IF NOT EXISTS `affiliate_audit_alerts` (`id` INT AUTO_INCREMENT PRIMARY KEY,`owner_id` INT DEFAULT NULL,`owner_label` VARCHAR(160) NOT NULL,`alert_type` ENUM('fraud','inactive') NOT NULL DEFAULT 'fraud',`metric` VARCHAR(190) NOT NULL,`risk` VARCHAR(40) NOT NULL,`color` VARCHAR(20) NOT NULL DEFAULT '#ef5350',`active` TINYINT(1) NOT NULL DEFAULT 1,`created_at` DATETIME NOT NULL DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci",
]],

// ── MÓDULO: Módulo IA ─────────────────────────────────────────────────────────
['id'=>'m100_ai_module','module'=>'Módulo IA','icon'=>'fa-robot','description'=>'ai_projects, ai_chats, ai_messages y ai_notifications_log',
'sqls'=>[
"CREATE TABLE IF NOT EXISTS `ai_projects` (`id` INT AUTO_INCREMENT PRIMARY KEY,`nombre` VARCHAR(150) NOT NULL,`descripcion` TEXT DEFAULT NULL,`contexto_sistema` TEXT DEFAULT NULL,`activo` TINYINT(1) DEFAULT 1,`created_at` DATETIME DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `ai_chats` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_proyecto` INT DEFAULT NULL,`titulo` VARCHAR(200) DEFAULT 'Chat sin título',`usuario` VARCHAR(100) DEFAULT NULL,`activo` TINYINT(1) DEFAULT 1,`created_at` DATETIME DEFAULT current_timestamp(),`updated_at` DATETIME DEFAULT current_timestamp() ON UPDATE current_timestamp(),KEY `idx_aichat_proyecto` (`id_proyecto`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `ai_messages` (`id` INT AUTO_INCREMENT PRIMARY KEY,`id_chat` INT NOT NULL,`rol` ENUM('user','assistant','system') NOT NULL DEFAULT 'user',`contenido` LONGTEXT NOT NULL,`tokens_prompt` INT DEFAULT 0,`tokens_completion` INT DEFAULT 0,`created_at` DATETIME DEFAULT current_timestamp(),KEY `idx_aimsg_chat` (`id_chat`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
"CREATE TABLE IF NOT EXISTS `ai_notifications_log` (`id` INT AUTO_INCREMENT PRIMARY KEY,`tipo` VARCHAR(60) NOT NULL,`mensaje` TEXT DEFAULT NULL,`datos_json` LONGTEXT DEFAULT NULL,`created_at` DATETIME DEFAULT current_timestamp()) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
]],

]; // fin $allMigrations

// ─────────────────────────────────────────────────────────────────────────────
// MANEJADORES AJAX
// ─────────────────────────────────────────────────────────────────────────────
$action = $_GET['action'] ?? $_POST['action'] ?? '';

function stream_ndjson(): void {
    header('Content-Type: application/x-ndjson; charset=utf-8');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no');
    @ini_set('output_buffering', 'off');
    @ini_set('zlib.output_compression', '0');
    while (ob_get_level() > 0) @ob_end_flush();
    ob_implicit_flush(true);
}
function emit(array $d): void {
    echo json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
    @flush();
}

if ($action === 'status') {
    header('Content-Type: application/json');
    $applied = array_flip($pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN));
    $result = array_map(fn($m) => ['id'=>$m['id'],'module'=>$m['module'],'icon'=>$m['icon'],'description'=>$m['description'],'status'=>isset($applied[$m['id']])?'applied':'pending'], $allMigrations);
    echo json_encode(['ok'=>true,'migrations'=>$result]);
    exit;
}

if ($action === 'run_pending' || $action === 'run_one') {
    stream_ndjson();
    $applied = array_flip($pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN));
    $toRun = [];
    if ($action === 'run_one') {
        $tid = $_POST['id'] ?? '';
        foreach ($allMigrations as $m) { if ($m['id'] === $tid) { $toRun[] = $m; break; } }
    } else {
        foreach ($allMigrations as $m) { if (!isset($applied[$m['id']])) $toRun[] = $m; }
    }
    $batch = (int)$pdo->query("SELECT COALESCE(MAX(batch),0)+1 FROM migrations")->fetchColumn();
    $total = count($toRun); $done = 0;
    emit(['type'=>'start','total'=>$total,'message'=>"Ejecutando $total migración(es)"]);
    foreach ($toRun as $m) {
        emit(['type'=>'running','id'=>$m['id'],'description'=>$m['description'],'module'=>$m['module']]);
        $errors = [];
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS=0"); } catch (Throwable $ignored) {}
        foreach ($m['sqls'] as $sql) {
            try { $pdo->exec($sql); }
            catch (PDOException $e) { $errors[] = substr($e->getMessage(), 0, 200); }
        }
        try { $pdo->exec("SET FOREIGN_KEY_CHECKS=1"); } catch (Throwable $ignored) {}
        if (empty($errors)) {
            try { $pdo->prepare("INSERT IGNORE INTO migrations (migration, batch) VALUES (?, ?)")->execute([$m['id'], $batch]); } catch (Throwable $ignored) {}
            $done++;
            emit(['type'=>'done','id'=>$m['id'],'done'=>$done,'total'=>$total,'pct'=>(int)round($done/$total*100)]);
        } else {
            emit(['type'=>'error','id'=>$m['id'],'errors'=>$errors]);
        }
    }
    emit(['type'=>'finish','done'=>$done,'total'=>$total,'message'=>"$done de $total migraciones aplicadas."]);
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// RENDER: calcular estado por módulo
// ─────────────────────────────────────────────────────────────────────────────
$appliedIds = array_flip($pdo->query("SELECT migration FROM migrations")->fetchAll(PDO::FETCH_COLUMN));
$modules = [];
foreach ($allMigrations as $m) {
    $mod = $m['module'];
    if (!isset($modules[$mod])) $modules[$mod] = ['icon'=>$m['icon'],'total'=>0,'applied'=>0,'migrations'=>[]];
    $modules[$mod]['total']++;
    $st = isset($appliedIds[$m['id']]) ? 'applied' : 'pending';
    if ($st === 'applied') $modules[$mod]['applied']++;
    $modules[$mod]['migrations'][] = $m + ['status'=>$st];
}
$totalMig = count($allMigrations);
$totalApplied = count(array_filter($allMigrations, fn($m) => isset($appliedIds[$m['id']])));
$totalPending = $totalMig - $totalApplied;
?><!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Migraciones BD | <?= htmlspecialchars(config_loader_system_name()) ?></title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <link rel="stylesheet" href="assets/css/inventory-suite.css">
    <style>
        .mig-card { transition: all .2s; border: 1px solid rgba(255,255,255,.06); }
        .mig-card.pending  { border-left: 3px solid #f59e0b; }
        .mig-card.applied  { border-left: 3px solid #22c55e; }
        .mig-card.running  { border-left: 3px solid #60a5fa; animation: mig-pulse .8s infinite; }
        .mig-card.error    { border-left: 3px solid #ef4444; }
        @keyframes mig-pulse { 0%,100%{opacity:1} 50%{opacity:.55} }
        .log-box { background:#0f1720; color:#d3e8ff; border-radius:.75rem; min-height:100px; max-height:300px; overflow:auto; padding:1rem; font-family:monospace; font-size:.82rem; }
        .mod-pct-bar { width:70px; height:5px; background:rgba(255,255,255,.1); border-radius:3px; overflow:hidden; }
        .mod-pct-fill { height:100%; background:#22c55e; transition:width .4s; }
    </style>
</head>
<body class="pb-5 inventory-suite">
<div class="container-fluid shell inventory-shell py-4 py-lg-5">

    <section class="glass-card inventory-hero p-4 p-lg-5 mb-4">
        <div class="d-flex flex-column flex-lg-row justify-content-between gap-4 align-items-start">
            <div>
                <div class="section-title text-white-50 mb-2">Sistema / Base de Datos</div>
                <h1 class="h2 fw-bold mb-2"><i class="fas fa-layer-group me-2"></i>Gestor de Migraciones</h1>
                <p class="mb-3 text-white-50">Detecta y aplica tablas y columnas faltantes por módulo. Idempotente: seguro de re-ejecutar.</p>
                <div class="d-flex flex-wrap gap-2">
                    <span class="kpi-chip"><i class="fas fa-database me-1"></i><?= $totalMig ?> migraciones</span>
                    <span class="kpi-chip"><i class="fas fa-check-circle me-1 text-success"></i><?= $totalApplied ?> aplicadas</span>
                    <span class="kpi-chip"><i class="fas fa-clock me-1 <?= $totalPending>0?'text-warning':'' ?>"></i><?= $totalPending ?> pendientes</span>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-start">
                <?php if ($totalPending > 0): ?>
                <button id="btnRunAll" class="btn btn-success fw-bold"><i class="fas fa-play me-1"></i>Aplicar <?= $totalPending ?> pendientes</button>
                <?php else: ?>
                <button class="btn btn-outline-success fw-bold" disabled><i class="fas fa-check me-1"></i>Todo al día</button>
                <?php endif; ?>
                <button id="btnRefresh" class="btn btn-outline-light"><i class="fas fa-sync me-1"></i>Actualizar</button>
                <a href="pos_admin.php" class="btn btn-outline-light"><i class="fas fa-arrow-left me-1"></i>Administración</a>
            </div>
        </div>
    </section>

    <!-- Panel de progreso (oculto inicialmente) -->
    <div id="progressSection" class="glass-card p-4 mb-4 d-none">
        <div class="d-flex justify-content-between align-items-center mb-2">
            <div class="fw-bold small" id="progressLabel">Ejecutando migraciones...</div>
            <span id="progressCount" class="small text-muted">0/0</span>
        </div>
        <div class="progress mb-3" style="height:14px;">
            <div id="progressBar" class="progress-bar progress-bar-striped progress-bar-animated bg-success" style="width:0%">0%</div>
        </div>
        <div class="log-box" id="logBox"></div>
    </div>

    <!-- Módulos -->
    <?php foreach ($modules as $modName => $mod):
        $modPending = $mod['total'] - $mod['applied'];
        $modPct = $mod['total'] > 0 ? round($mod['applied']/$mod['total']*100) : 0;
        $safeMod = preg_replace('/[^a-z0-9]/i','_', $modName);
    ?>
    <div class="glass-card p-4 mb-3 border-start border-3 <?= $modPending===0?'border-success':'border-warning' ?>" id="mod-<?= $safeMod ?>">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="d-flex align-items-center gap-3">
                <div style="background:rgba(255,255,255,.07);border-radius:.5rem;width:40px;height:40px;display:grid;place-items:center;font-size:1rem;flex-shrink:0;">
                    <i class="fas <?= htmlspecialchars($mod['icon']) ?>"></i>
                </div>
                <div>
                    <div class="section-title" style="font-size:.65rem;">Módulo</div>
                    <h2 class="h6 fw-bold mb-0"><?= htmlspecialchars($modName) ?></h2>
                </div>
                <?php if ($modPending > 0): ?>
                <span class="badge bg-warning text-dark" style="font-size:.68rem;"><?= $modPending ?> pendiente<?= $modPending>1?'s':'' ?></span>
                <?php else: ?>
                <span class="badge bg-success" style="font-size:.68rem;"><i class="fas fa-check me-1"></i>Al día</span>
                <?php endif; ?>
            </div>
            <div class="d-flex align-items-center gap-3">
                <div class="text-end">
                    <div class="tiny text-muted"><?= $mod['applied'] ?>/<?= $mod['total'] ?></div>
                    <div class="mod-pct-bar mt-1"><div class="mod-pct-fill" style="width:<?= $modPct ?>%"></div></div>
                </div>
                <?php if ($modPending > 0): ?>
                <button class="btn btn-sm btn-outline-warning btn-run-module" data-module="<?= htmlspecialchars($modName) ?>">
                    <i class="fas fa-play me-1"></i>Aplicar módulo
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="row g-2">
            <?php foreach ($mod['migrations'] as $m): ?>
            <div class="col-sm-6 col-lg-4" id="card-<?= htmlspecialchars($m['id']) ?>">
                <div class="mig-card glass-card p-3 h-100 <?= $m['status'] ?>">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <div class="small fw-bold" style="line-height:1.3;"><?= htmlspecialchars($m['description']) ?></div>
                        <div class="d-flex gap-1 flex-shrink-0 align-items-center">
                            <?php if ($m['status'] === 'pending'): ?>
                            <button class="btn btn-outline-warning py-0 px-1 btn-run-one" data-id="<?= htmlspecialchars($m['id']) ?>" title="Aplicar" style="font-size:.7rem;">
                                <i class="fas fa-play"></i>
                            </button>
                            <?php endif; ?>
                            <span class="badge <?= $m['status']==='applied'?'bg-success':'bg-warning text-dark' ?>" style="font-size:.6rem;">
                                <?= $m['status']==='applied'?'OK':'Pendiente' ?>
                            </span>
                        </div>
                    </div>
                    <div class="text-muted mt-1" style="font-size:.65rem;font-family:monospace;"><?= htmlspecialchars($m['id']) ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

</div>

<?php include_once 'menu_master.php'; ?>

<script>
const logBox = document.getElementById('logBox');
const progressSection = document.getElementById('progressSection');
const progressBar = document.getElementById('progressBar');
const progressLabel = document.getElementById('progressLabel');
const progressCount = document.getElementById('progressCount');

function logLine(msg, type = 'info') {
    const c = {ok:'#86efac', error:'#fca5a5', info:'#d3e8ff', running:'#93c5fd'};
    const d = document.createElement('div');
    d.style.marginBottom = '2px';
    d.innerHTML = `<span style="color:${c[type]??c.info}">[${new Date().toLocaleTimeString()}]</span> ${String(msg).replace(/</g,'&lt;')}`;
    logBox.appendChild(d);
    logBox.scrollTop = logBox.scrollHeight;
}

function setCardState(id, state) {
    const wrap = document.getElementById('card-' + id);
    if (!wrap) return;
    const inner = wrap.querySelector('.mig-card');
    if (!inner) return;
    inner.classList.remove('pending','applied','running','error');
    inner.classList.add(state);
    const badge = inner.querySelector('.badge');
    if (badge) {
        const map = {applied:['bg-success','OK'], running:['bg-primary','...'], error:['bg-danger','Error'], pending:['bg-warning text-dark','Pendiente']};
        badge.className = 'badge ' + (map[state]?.[0] ?? 'bg-secondary');
        badge.style.fontSize = '.6rem';
        badge.textContent = map[state]?.[1] ?? state;
    }
    if (state === 'applied') { const btn = inner.querySelector('.btn-run-one'); if (btn) btn.remove(); }
}

async function runMigrations(formData) {
    progressSection.classList.remove('d-none');
    progressBar.style.width = '0%'; progressBar.textContent = '0%';
    progressBar.className = 'progress-bar progress-bar-striped progress-bar-animated bg-info';
    progressLabel.textContent = 'Ejecutando...';
    logBox.innerHTML = '';

    const res = await fetch('admin_migracion.php', { method:'POST', body:formData });
    if (!res.ok || !res.body) { logLine('Error HTTP ' + res.status, 'error'); return; }
    const reader = res.body.getReader();
    const dec = new TextDecoder();
    let buf = '';
    while (true) {
        const { value, done } = await reader.read();
        if (done) break;
        buf += dec.decode(value, { stream:true });
        let idx;
        while ((idx = buf.indexOf('\n')) >= 0) {
            const line = buf.slice(0, idx).trim(); buf = buf.slice(idx + 1);
            if (!line) continue;
            const ev = JSON.parse(line);
            if (ev.type === 'start') {
                logLine(ev.message);
            } else if (ev.type === 'running') {
                setCardState(ev.id, 'running');
                logLine(`▶ [${ev.module}] ${ev.description}`, 'running');
            } else if (ev.type === 'done') {
                setCardState(ev.id, 'applied');
                const pct = ev.pct ?? 0;
                progressBar.style.width = pct + '%'; progressBar.textContent = pct + '%';
                progressCount.textContent = `${ev.done}/${ev.total}`;
                logLine(`✓ ${ev.id}`, 'ok');
            } else if (ev.type === 'error') {
                setCardState(ev.id, 'error');
                logLine(`✗ ${ev.id}: ${(ev.errors ?? []).join(' | ')}`, 'error');
            } else if (ev.type === 'finish') {
                progressBar.style.width = '100%'; progressBar.textContent = '100%';
                progressBar.className = 'progress-bar bg-success';
                progressLabel.textContent = ev.message;
                logLine('── ' + ev.message + ' ──', 'ok');
                setTimeout(() => location.reload(), 1800);
            }
        }
    }
}

document.getElementById('btnRunAll')?.addEventListener('click', () => {
    const fd = new FormData(); fd.set('action','run_pending'); runMigrations(fd);
});
document.getElementById('btnRefresh')?.addEventListener('click', () => location.reload());

document.querySelectorAll('.btn-run-one').forEach(btn => btn.addEventListener('click', () => {
    const fd = new FormData(); fd.set('action','run_one'); fd.set('id', btn.dataset.id); runMigrations(fd);
}));

document.querySelectorAll('.btn-run-module').forEach(btn => btn.addEventListener('click', () => {
    const modName = btn.dataset.module;
    const pendingBtns = [];
    document.querySelectorAll('.btn-run-one').forEach(b => {
        const card = b.closest('[id^="card-"]');
        if (!card) return;
        const inner = card.querySelector('.mig-card.pending');
        if (inner) pendingBtns.push(b.dataset.id);
    });
    if (!pendingBtns.length) return;
    (async () => {
        for (const id of pendingBtns) {
            const fd = new FormData(); fd.set('action','run_one'); fd.set('id', id);
            await runMigrations(fd);
        }
    })();
}));
</script>
</body>
</html>
