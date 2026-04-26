# PalWeb POS Marinero

Sistema de Punto de Venta (POS) monolítico desarrollado en PHP plano (sin framework), servido directamente desde `/var/www/`. Aplicación PWA con soporte offline y actualizaciones en tiempo real.

## 📋 Características Principales

- **POS completo** con autenticación por PIN, gestión de caja, ventas, devoluciones y facturación
- **Inventario en tiempo real** con motor Kardex (movimientos: VENTA, DEVOLUCION, ENTRADA, AJUSTE, MERMA)
- **Multi-tenant** (multi-empresa, multi-sucursal, multi-almacén)
- **PWA** – funciona como aplicación nativa en móviles y tablets
- **Cocina/Comandas** – vista de producción de pedidos
- **CRM** – gestión de clientes con múltiples teléfonos y direcciones
- **Contabilidad** – diario contable, cuentas, reportes fiscales
- **Sincronización** – cliente/servidor para modo offline
- **Integración WhatsApp** – bot automático para pedidos y consultas (Node.js bridge)
- **APK Android** – apps nativas para reservas offline y tracking de ventas

## 🛠️ Stack Tecnológico

| Componente | Tecnología |
|-----------|-----------|
| Backend | PHP 8.x (monolito sin framework) |
| Frontend | Bootstrap 5, FontAwesome 6, Vue.js (bundled en `assets/`) |
| Base de Datos | MySQL / MariaDB |
| Servidor Web | Apache / Nginx |
| PWA | Service Worker, Manifest JSON |
| WhatsApp Bridge | Node.js ≥22 (WhatsApp Web JS) |
| Android | Kotlin (Gradle) |
| Librerías PHP | PHPSpreadsheet, PDO |

## 📁 Estructura del Proyecto

```
/var/www/                    ← Raíz de deployment en producción
├── *.php                    ← Archivos PHP activos (pos.php, shop.php, etc.)
├── assets/                  ← Recursos compartidos (CSS, JS, imágenes)
│   ├── js/
│   ├── product_images/
│   └── ...
├── lang/                    ← Traducciones / idiomas
├── vendor/                  ← Dependencias Composer (PHPSpreadsheet, etc.)
├── migrations/              ← Scripts únicos de migración/arreglo DB
├── _deprecated/             ← Código archivado (no se usa en producción)
├── wa_web_bridge/           ← Puente Node.js para WhatsApp (requiere Node ≥22)
├── apk/
│   ├── ReservasOffline/     ← App Android (Kotlin) – Reservas sin conexión
│   └── SalesTracker/        ← App Android (Kotlin) – Tracking de ventas
├── api/                     ← Endpoints JSON API
├── pos_*.php                ← Módulos del POS (terminal, caja, compras, etc.)
├── accounting_*.php         ← Módulos contables
├── *._api.php               ← Endpoints API públicos/privados
├── db.php                   ← Conexión PDO global (define $pdo)
├── config_loader.php        ← Cargador de configuración desde pos.cfg
├── kardex_engine.php        ← Motor de inventario / Kardex
├── menu_master.php          ← Nav flotante + chat + selector de tema
└── manifest.json            ← PWA manifest (start_url: pos.php)
```

## ⚙️ Requisitos

- **PHP** 8.0+ (extensiones: pdo_mysql, json, session, gd/imagick opcional)
- **MySQL** 5.7+ o MariaDB 10.3+
- **Servidor Web** Apache con mod_rewrite o Nginx
- **Node.js** ≥22 (solo para wa_web_bridge)
- **Android SDK** (solo para builds de APK)

## 🚀 Instalación Rápida

### 1. Desplegar código

```bash
# En el servidor, como root o www-data:
cd /var/www/
# Clonar o copiar archivos del repositorio
git clone <repo-url> .
```

### 2. Base de datos

```sql
-- Crear base de datos (nombre por defecto: palweb_central)
CREATE DATABASE palweb_central CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- Importar schema (si existe un dump inicial)
mysql -u root -p palweb_central < schema_inicial.sql
```

### 3. Configuración

```bash
# Copiar plantilla de configuración
cp pos.cfg.example pos.cfg
# Editar con valores reales:
vi pos.cfg
```

**Variables clave en `pos.cfg`** (formato `clave=valor`):

```ini
db_host=127.0.0.1
db_name=palweb_central
db_user=admin_web
db_pass=tu_contraseña_segura
db_port=3306

id_empresa=1
id_sucursal=1
id_almacen=1

# PIN de cajeros (uno por línea, formato: PIN=NOMBRE)
1234=CAJERO1
5678=CAJERO2

# Claves VAPID para push notifications
vapid_public_key=...
vapid_private_key=...
```

### 4. Permisos

```bash
chown -R www-data:www-data /var/www/
chmod -R 755 /var/www/
# Directorio de imágenes de productos (fuera del webroot):
mkdir -p /home/marinero/product_images/
chown www-data:www-data /home/marinero/product_images/
```

### 5. Servidor Web (Apache ejemplo)

```apache
<VirtualHost *:80>
    ServerName pos.tudominio.com
    DocumentRoot /var/www/
    <Directory /var/www>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### 6. WhatsApp Bridge (opcional)

```bash
cd /var/www/wa_web_bridge
npm install
cp .env.example .env
# Editar .env: POS_BOT_VERIFY_TOKEN (debe coincidir con pos_bot.php)
npm start
# Escanear QR con WhatsApp → vinculación exitosa
```

## 🔧 Configuración

### Orden de carga de configuración

1. `db.php` – conexión PDO global `$pdo` (obligatorio primero)
2. `config_loader.php` – lee `pos.cfg` y popula `$config`
3. `pos_session_helpers.php`/`menu_master.php` – contexto de sesión

### Patrones críticos

- **Siempre** incluir `require_once 'db.php';` **ANTES** de `config_loader.php`.
- Páginas admin verifican `$_SESSION['admin_logged_in'] === true` (redirigen a `login.php`).
- Páginas públicas (`pos.php`, `shop.php`, `cocina.php`) No requieren sesión admin.
- APIs retornan JSON: `header('Content-Type: application/json')`.
- Consultas usan **prepared statements** PDO (parámetros posicionales `?` o nombrados `:param`).
- Fechas en formato `Y-m-d H:i:s`.
- IDs multi-tenant: `id_empresa`, `id_sucursal`, `id_almacen` propagados desde `$config`.

### KardexEngine

```php
$kdx = new KardexEngine($pdo);
$kdx->registrarMovimiento(
    $sku, $almacen, $sucursal, 'VENTA',
    $cantidad, $referencia, $costo, $usuario, $fecha
);
```

Tipos válidos: `VENTA`, `DEVOLUCION`, `ENTRADA`, `AJUSTE`, `MERMA`.

## 🧪 Comandos de Desarrollo

```bash
# Revisión de sintaxis PHP
php -l pos.php

# Cliente de sincronización manual
php sync_client.php

# Migración / fix de base de datos
php migrations/fix_db_crm.php

# WhatsApp Bridge (Node ≥22)
cd wa_web_bridge && npm start

# Android – ReservasOffline (test + build)
cd apk/ReservasOffline && ./gradlew assembleDebug testDebugUnitTest

# Android – SalesTracker (build)
cd apk/SalesTracker && ./gradlew assembleDebug
```

## 📱 Aplicaciones Móviles

| App | Ruta | Descripción |
|-----|------|-------------|
| **Reservas Offline** | `apk/ReservasOffline/` | App Kotlin para reservas sin conexión, sincroniza con API |
| **Sales Tracker** | `apk/SalesTracker/` | Tracking de ventas y reportes en campo |

## 🔒 Consideraciones de Seguridad

- **NUNCA** cometer secretos reales: `pos.cfg` (PINs, claves VAPID), `.env` (bridge), sesiones de WhatsApp, dumps SQL.
- El directorio `marinero/` está en `.gitignore` (archivos sensibles fuera del repo).
- APIs: validar `$_SESSION` o tokens cuando corresponda.
- CSP headers activos en `pos.php` (script-src incluye `'unsafe-inline'` y `'unsafe-eval'` para dev; en producción ajustar).

## 📝 Convenciones de Código

- **Indentación**: 4 espacios.
- **PHP**: procedural, identifiers en español (conceptos de negocio).
- **BD**: `DECIMAL(12,2)` para monetarios; `floatval()` en PHP.
- **UUIDs**: Ventas → `uniqid('V-', true)` o `uniqid('pos_', true)`; Kardex → `uniqid('kdx_')`.
- **Comentarios y columnas DB**: en español.
- **Android (Kotlin)**: nombre de archivo == clase principal.
- **Node (bridge)**: módulos CommonJS pequeños.

## 🧩 Integraciones Externas

- **WhatsApp Bot** – `pos_bot.php` + `wa_web_bridge/` (webhook entrante `pos_bot_api.php?action=web_incoming`)
- **Push Notifications** – VAPID keys en `pos.cfg`, service worker `sw.js`
- **Facebook** – `fb.php`, `fb_bot.php` (scraping/automatización)
- **Water Sync** – `water_sync.php` (sincronización remota con token en `api/water_sync_token.txt`)

## 🐛 Debugging

```bash
# Ver errores PHP (solo desarrollo)
ini_set('display_errors', 1);
error_reporting(E_ALL);

# Logs de servicio (systemd si está instalado como servicio)
journalctl -u palweb-fb-bot.service -f
```

Scripts de reparación útiles:
- `fix_db_crm.php` – corrige estructura CRM
- `fix_sales.php` – repara ventas corruptas
- `rebuild_kardex_product.php` – reconstruye saldos kardex

## 📄 Licencia

Propietario – PalWeb Systems. Código confidencial, no distribuir.

## 👤 Contacto / Soporte

Para reportar bugs o solicitar features, contactar al equipo de desarrollo de PalWeb.
