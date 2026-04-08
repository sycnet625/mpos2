# Repository Guidelines

## Project Structure & Module Organization
This is **PalWeb POS Marinero** — a flat PHP monolith (no framework) served directly by Apache/Nginx from `/var/www/`. All active PHP files live in the root. The app is a PWA (`manifest.json` with `start_url: pos.php`). Frontend: Bootstrap 5 + FontAwesome 6 + Vue.js (bundled in `assets/`).

Most active code lives in `/var/www/*.php`, with shared assets in `/var/www/assets/`, translations in `/var/www/lang/`, one-time DB fix scripts in `/var/www/migrations/`, and archived code in `/var/www/_deprecated/`. Composer vendor deps (PHPSpreadsheet, etc.) sit in `/var/www/vendor/`. Keep new PHP pages near related entry points and follow existing prefixes: `pos_*.php`, `*_api.php`, `accounting_*.php`.

Side projects: `/var/www/wa_web_bridge/` (Node.js WhatsApp bridge, requires Node >=22), `/var/www/apk/ReservasOffline/` and `/var/www/apk/SalesTracker/` (Android/Kotlin).

## Architecture & Dependency Layers

```
db.php              ← Global $pdo (PDO). Required by ~80 files. Sets timezone America/Havana (UTC-5).
config_loader.php   ← Loads pos.cfg into $config. Required by ~40 files. Place AFTER db.php.
kardex_engine.php   ← Class KardexEngine. Inventory journal. Required by 9 files.
menu_master.php     ← Floating nav + chat widget + theme switcher. Included by ~30 UI pages.
pos_controller.php  ← Loads config + product queries for POS terminal.
```

**Critical patterns:**
- Always `require_once 'db.php';` then `require_once 'config_loader.php';` in that order.
- Admin pages check `$_SESSION['admin_logged_in'] === true`, redirecting to `login.php`. Public pages (`shop.php`, `cocina.php`, `pos.php`) skip this.
- API endpoints return JSON via `header('Content-Type: application/json')` and read POST body with `json_decode(file_get_contents('php://input'), true)`.
- Multi-tenant IDs (`id_empresa`, `id_sucursal`, `id_almacen`) propagate through all queries from `$config`.
- Tables are auto-created with `CREATE TABLE IF NOT EXISTS` directly in the PHP files that need them.
- Product images stored at `/home/marinero/product_images/` (outside webroot), served via `image.php?code={codigo}`.

**KardexEngine quirk:** `registrarMovimiento()` supports two signatures. If 3rd arg is numeric → new (9 params: sku, almacen, sucursal, TIPO, cantidad, ref, costo, user, fecha). If string → legacy (8 params). Movement types: `VENTA`, `DEVOLUCION`, `ENTRADA`, `AJUSTE`, `MERMA`.

## Build, Test, and Development Commands
No global build step. Use focused checks:

```bash
php -l pos.php              # Syntax check any edited PHP file
php sync_client.php          # Run branch sync client manually
php migrations/fix_db_crm.php # Run a migration/fix script
cd wa_web_bridge && npm start # WhatsApp bridge (Node >=22, needs .env)
cd apk/ReservasOffline && ./gradlew assembleDebug testDebugUnitTest
cd apk/SalesTracker && ./gradlew assembleDebug
```

## Coding Style & Naming Conventions
4-space indentation, procedural PHP, Spanish-facing identifiers for business concepts. Use PDO prepared statements (positional `?` or named `:param`). Monetary values: `DECIMAL(12,2)` in MySQL, `floatval()` in PHP. UUIDs: sales use `uniqid('V-', true)` or `uniqid('pos_', true)`, kardex uses `uniqid('kdx_')`. Dates always `Y-m-d H:i:s`. All UI, comments, and DB columns are in Spanish.

For Android (Kotlin): align file names with main class. For Node (bridge): small CommonJS modules.

## Testing Guidelines
PHP: syntax checks + manual browser/API smoke test for the affected flow. `apk/ReservasOffline` has unit and Android tests under `app/src/test` and `app/src/androidTest`; name them `*Test.kt`. `SalesTracker` has no automated test suite — document manual validation in the PR.

## Commit & Pull Request Guidelines
Short, imperative summaries, often in Spanish. Keep commits focused. PRs should include: problem/solution summary, affected paths, setup or migration notes (`pos.cfg`, `.env`, SQL), screenshots for UI changes, and exact verification commands run.

## Security & Configuration Tips
Do not commit real secrets, WhatsApp session data, database dumps with live data, or local `.env` overrides. Treat `pos.cfg` (contains cashier PINs, VAPID keys), bridge auth directories, and backup SQL files as sensitive. The `marinero/` directory is gitignored. When changing integrations or sync behavior, note required tokens, headers, and cron/manual execution steps in the PR.
