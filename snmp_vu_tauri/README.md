# PalWeb SNMP VU Tauri

Migracion inicial desde Electron a Tauri.

Estado actual:
- UI gadget portada a Tauri en una sola ventana frameless.
- Configuracion local persistente.
- Poll local por SNMP GET y ping desde Rust.
- SNMP walk, tray y perfiles import/export.
- Build Windows portable lista para publicar como ZIP.
- Scripts preparados para bundle Windows y publicacion de metadata.

Comandos:
- `php scripts/bump_version.php patch`
- `php scripts/bump_version.php minor`
- `php scripts/bump_version.php major`
- `cargo run --manifest-path src-tauri/Cargo.toml`
- `cargo build --release --manifest-path src-tauri/Cargo.toml`

Build Windows portable desde Linux:
- `cargo build --release --target x86_64-pc-windows-gnu --manifest-path src-tauri/Cargo.toml`

Bundle Windows real desde Windows:
- `powershell -ExecutionPolicy Bypass -File .\scripts\build_windows_bundle.ps1`

Publicar metadata de update:
- `php scripts/publish_update_json.php 1.0.0 20260316.000100 https://www.palweb.net/apk/snmp-vu-monitor.zip /var/www/apk/snmp-vu-tauri-update.json "Notas de la build"`

Versionado:
- `version` semantica: se sube con `scripts/bump_version.php` y sincroniza `package.json`, `Cargo.toml` y `tauri.conf.json`.
- `build`: se genera automaticamente en cada compilacion y se inyecta como `APP_BUILD`.

Limites actuales:
- El entorno Linux actual permite sacar el paquete portable Windows, pero no un instalador Windows final fiable.
- Para generar `NSIS` o `MSI` y probarlo correctamente, hay que ejecutar el script de bundle en Windows.
