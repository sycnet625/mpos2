# PalWeb POS Marinero - Guía del Proyecto

Este proyecto es un sistema monolítico de Punto de Venta (POS) y comercio electrónico diseñado para un negocio en Cuba. No utiliza frameworks pesados; es una aplicación PHP pura servida directamente por Apache/Nginx, con una arquitectura PWA.

## Resumen Tecnológico
- **Backend:** PHP 7.x/8.x (Arquitectura procedimental mayoritariamente).
- **Base de Datos:** MySQL (acceso vía PDO en `db.php`).
- **Frontend:** Bootstrap 5, FontAwesome 6, Vue.js (embebido en `assets/`).
- **Configuración:** Archivo JSON `pos.cfg` cargado mediante `config_loader.php`.
- **Zona Horaria:** `America/Havana` (UTC-5), configurada en PHP y MySQL.
- **Idioma:** Código, comentarios y base de datos íntegramente en español.

## Estructura de Archivos Clave
- `db.php`: Conexión central a la base de datos (PDO).
- `config_loader.php`: Carga la configuración de `pos.cfg` y gestiona contextos de sucursal/almacén.
- `kardex_engine.php`: Motor central de inventario (Clase `KardexEngine`).
- `pos.php`: Terminal de ventas principal.
- `shop.php` / `shop2.php`: Interfaz de tienda online pública.
- `index.php`: Puerta de enlace (Gateway) para la API REST.
- `pos_*.php`: Módulos específicos del POS (gastos, compras, auditoría).
- `accounting_*.php`: Módulos del sistema contable.
- `wa_web_bridge/`: Puente de integración con WhatsApp (Node.js).

## Comandos de Desarrollo y Mantenimiento

### Verificación de Sintaxis
```bash
php -l nombre_del_archivo.php
```

### Sincronización de Sucursales
```bash
# Ejecutar el cliente de sincronización manualmente
php sync_client.php
```

### Puente de WhatsApp (Node.js)
```bash
cd wa_web_bridge && npm start
```

### Scripts de Reparación/Migración
Los scripts en la raíz o en `migrations/` se ejecutan directamente con PHP:
```bash
php fix_db_crm.php
```

## Convenciones de Desarrollo

### Estilo de Código
- **Indentación:** 4 espacios.
- **Nomenclatura:** Identificadores en español (`id_producto`, `ventas_cabecera`).
- **Inclusión de Dependencias:** Usar siempre `require_once 'db.php';` y `require_once 'config_loader.php';` al inicio de nuevos scripts.
- **Seguridad:** Utilizar sentencias preparadas de PDO para todas las consultas SQL.

### Gestión de Inventario (Kardex)
Todas las operaciones de stock deben pasar por `KardexEngine.php`.
- Tipos de movimiento: `VENTA`, `DEVOLUCION`, `ENTRADA`, `AJUSTE`, `MERMA`.
- Soporta firmas de 8 y 9 parámetros (v2 incluye `id_sucursal`).

### Configuración Multi-inquilino
El sistema utiliza `id_empresa`, `id_sucursal` e `id_almacen` para filtrar datos. Estos valores se propagan globalmente a través del array `$config` cargado por `config_loader.php`.

### Imágenes de Productos
Se almacenan fuera del webroot en `/home/marinero/product_images/` y se sirven dinámicamente mediante `image.php?code={codigo}`.

## Notas de Seguridad
- No subir `pos.cfg` con credenciales reales.
- El acceso a las páginas de administración requiere `$_SESSION['admin_logged_in'] === true`.
- Los endpoints de API (`*_api.php`) suelen requerir un token Bearer o una clave API en el encabezado.
