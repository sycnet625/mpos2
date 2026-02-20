# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

**PalWeb POS Marinero** — A monolithic PHP point-of-sale and e-commerce system for a Cuban business. No framework; all PHP files are in the project root, served directly by Apache/Nginx. The app is a PWA (manifest.json with `start_url: pos.php`).

- **Database**: MySQL (`palweb_central`) via PDO, connection in `db.php`
- **Frontend**: Bootstrap 5 + FontAwesome 6 + Vue.js (bundled in `assets/`)
- **Timezone**: `America/Havana` (UTC-5), set in both PHP and MySQL
- **Config**: `pos.cfg` (JSON) — stores `id_empresa`, `id_sucursal`, `id_almacen`, cashier PINs, shop name, delivery rates
- **Language**: All UI, comments, and DB columns are in Spanish

## Architecture

### Dependency Layers

```
db.php                  ← Global $pdo (PDO). Required by ~80 files.
config_loader.php       ← Loads pos.cfg into $config. Required by ~40 files.
kardex_engine.php       ← Class KardexEngine. Inventory journal. Required by 9 files.
pos_audit.php           ← Function log_audit(). Required by 4 files.
pos_newprod.php         ← Reusable product-creation form. Included by 4 files.
menu_master.php         ← Floating nav menu + chat widget + theme switcher. Included by ~30 UI pages.
tools_unit_converter.php← Conditionally included by menu_master.php.
pos_controller.php      ← Loads config + product queries for POS terminal.
```

### Key Patterns

- **Config loading**: Use `require_once 'config_loader.php';` to get the `$config` array. This file centralizes all pos.cfg reading with sensible defaults. Place it after `require_once 'db.php';`.
- **Session guard**: Admin pages check `$_SESSION['admin_logged_in'] === true`, redirecting to `login.php` if absent. Public pages (`shop.php`, `cocina.php`, `pos.php`) skip this.
- **API endpoints** return JSON with `header('Content-Type: application/json')` and read POST body via `json_decode(file_get_contents('php://input'), true)`.
- **Multi-tenant IDs**: `id_empresa`, `id_sucursal`, `id_almacen` propagate through queries. The values come from `$config` (loaded via config_loader.php).

### KardexEngine (kardex_engine.php)

Central inventory system. Supports dual call signatures for backward compatibility:
- **Legacy (8 params)**: `registrarMovimiento(sku, almacen, TIPO, cantidad, ref, costo, user, fecha)`
- **New (9 params)**: `registrarMovimiento(sku, almacen, sucursal, TIPO, cantidad, ref, costo, user, fecha)`

Detection: if 3rd arg is numeric → new signature; if string → legacy. Movement types: `VENTA`, `DEVOLUCION`, `ENTRADA`, `AJUSTE`, `MERMA`.

### Entry Points

| URL | Purpose | Auth |
|-----|---------|------|
| `login.php` | Admin login | None (is the login page) |
| `pos.php` | POS terminal | PIN-based (cashiers in pos.cfg) |
| `shop.php` / `shop2.php` | Public online store | None |
| `cocina.php` | Kitchen display | None |
| `dashboard.php` | Admin dashboard | Session |
| `index.php` | REST API gateway | Bearer token (`API_TOKEN_SECRETO`) |
| `chat_api.php` | Chat messaging API | None |
| `ventas_api.php` | Sales processing API | None |
| `pos_save.php` | POS sale persistence | None (called via AJAX from POS) |
| `api_sync_server.php` | Multi-branch sync server | X-API-KEY header |
| `sync_client.php` | CLI sync client | N/A (run via `php sync_client.php`) |

### Database Tables (Key)

- `productos` — Product catalog (keyed by `codigo`)
- `stock_almacen` — Stock per warehouse (`id_producto` + `id_almacen`)
- `kardex` — Inventory movement journal
- `ventas_cabecera` / `ventas_detalle` — Sales header/detail
- `compras_cabecera` / `compras_detalle` — Purchase header/detail
- `caja_sesiones` — Cash register sessions
- `clientes` — CRM clients
- `chat_messages` — Live chat messages
- `auditoria_pos` — Audit log
- `pedidos_cabecera` / `pedidos_detalle` — Orders (delivery/reservations)
- `contabilidad_cuentas` / `contabilidad_diario` — Accounting chart and journal
- `mermas_cabecera` / `mermas_detalle` — Shrinkage records
- `recetas_cabecera` / `recetas_detalle` — Production recipes
- `facturas` / `facturas_detalle` — Invoices
- `sync_journal` — Multi-branch synchronization log

### File Naming Conventions

- `pos_*.php` — POS module pages (purchases, refunds, expenses, config, etc.)
- `*_api.php` — JSON API endpoints
- `accounting_*.php` — Accounting module pages

### Project Directory Structure

- `/` — All active PHP files (flat structure, no subdirectories for code)
- `/assets/` — CSS (Bootstrap, FontAwesome), JS (Bootstrap, Vue.js), webfonts
- `/migrations/` — One-time DB fix/install scripts (`fix_*.php`, `install_*.php`, `reset2.php`)
- `/_deprecated/` — Removed duplicates and orphaned files (kept as backup)
- `/backups/` — Database backup files
- `/api/` — Legacy API directory (mostly empty after cleanup)

## Development

### Running Locally

The project runs under a standard LAMP stack (Apache/Nginx + PHP + MySQL). No build step, no package manager, no compilation.

```bash
# Restart web server after config changes
sudo systemctl restart apache2   # or nginx + php-fpm

# Test a specific PHP file for syntax errors
php -l filename.php

# Run the sync client manually
php sync_client.php

# Run a migration script
php migrations/fix_db_crm.php
```

### Product Images

Images are stored at `/home/marinero/product_images/` (outside webroot), named `{codigo}.jpg`. Served via `image.php?code={codigo}`.

### Sync System

- `sync_client.php` runs on local branches, pushes sales/pulls products to/from the central server
- `api_sync_server.php` is the server-side receiver (designed for AWS deployment)
- Sync key is configured in `pos.cfg` (`sync_api_key`) and must match both sides

## Conventions

- All monetary values use `DECIMAL(12,2)` in MySQL and `floatval()` in PHP
- UUIDs for sales are generated with `uniqid('V-', true)` or `uniqid('pos_', true)`
- Kardex UUIDs use `uniqid('kdx_')`
- Dates always in `Y-m-d H:i:s` format
- All DB queries use PDO prepared statements with positional (`?`) or named (`:param`) placeholders
- Tables are auto-created with `CREATE TABLE IF NOT EXISTS` directly in the PHP files that need them
- New files should use `require_once 'config_loader.php';` instead of inlining the pos.cfg read pattern
