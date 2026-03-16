# PalWeb SNMP VU Tauri

Migracion inicial desde Electron a Tauri.

Estado actual:
- UI gadget portada a Tauri en una sola ventana frameless.
- Configuracion local persistente.
- Poll local por SNMP GET y ping desde Rust.
- SNMP walk, tray y perfiles import/export.
- Build Windows portable lista para publicar como ZIP.

Comandos:
- `cargo run --manifest-path src-tauri/Cargo.toml`
- `cargo build --release --manifest-path src-tauri/Cargo.toml`
