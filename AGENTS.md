# Repository Guidelines

## Project Structure & Module Organization
This repository is a flat PHP monolith served from the web root. Most active code lives in `/var/www/*.php`, with shared assets in `/var/www/assets/`, translations in `/var/www/lang/`, repair scripts in `/var/www/migrations/`, and archived code in `/var/www/_deprecated/`. Keep new PHP pages near related entry points and follow existing prefixes such as `pos_*.php`, `*_api.php`, and `accounting_*.php`.

Side projects live in `/var/www/wa_web_bridge/` for the Node.js WhatsApp bridge and `/var/www/apk/ReservasOffline/` plus `/var/www/apk/SalesTracker/` for Android apps.

## Build, Test, and Development Commands
There is no global build step for the PHP app. Use focused checks:

```bash
php -l pos.php
php sync_client.php
cd wa_web_bridge && npm start
cd apk/ReservasOffline && ./gradlew assembleDebug testDebugUnitTest
cd apk/SalesTracker && ./gradlew assembleDebug
```

`php -l` validates syntax for edited PHP files. `php sync_client.php` runs the branch sync client manually. `npm start` launches the WhatsApp bridge. The Gradle commands build the Android APKs; `testDebugUnitTest` runs Kotlin unit tests where present.

## Coding Style & Naming Conventions
Match the existing codebase: 4-space indentation, procedural PHP where appropriate, and Spanish-facing identifiers for business concepts. Load shared dependencies with `require_once 'db.php';` and `require_once 'config_loader.php';`, prefer PDO prepared statements, keep dates in `Y-m-d H:i:s`, and name new endpoints with the established `*_api.php` suffix.

For Android, keep Kotlin file names aligned with the main class (`MainViewModel.kt`, `Repository.kt`). For Node, keep bridge logic in small CommonJS modules.

## Testing Guidelines
PHP changes should pass syntax checks for every edited file and a manual browser or API smoke test for the affected flow. `apk/ReservasOffline` includes unit and Android tests under `app/src/test` and `app/src/androidTest`; place new tests beside the feature they cover and name them `*Test.kt`. `SalesTracker` has no visible automated test suite, so document manual validation in the PR.

## Commit & Pull Request Guidelines
Recent commits use short, imperative summaries, often in Spanish, for example `Remove sensitive WhatsApp session artifacts` and `Sync WhatsApp API file with root copy`. Keep commits focused. PRs should include: a short problem/solution summary, affected paths, setup or migration notes (`pos.cfg`, `.env`, SQL), screenshots for UI changes, and exact verification commands run.

## Security & Configuration Tips
Do not commit real secrets, WhatsApp session data, database dumps with live data, or local `.env` overrides. Treat `pos.cfg`, bridge auth directories, and backup SQL files as sensitive. When changing integrations or sync behavior, note required tokens, headers, and cron/manual execution steps in the PR.
