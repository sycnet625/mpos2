# PalWeb SNMP VU Tauri

Migracion inicial desde Electron a Tauri.

Estado actual:
- UI gadget portada a Tauri en una sola ventana frameless.
- Configuracion local persistente.
- Poll local por SNMP GET y ping desde Rust.
- Base lista para seguir con tray, OTA y bundle Windows.

Comandos:
- `cargo run --manifest-path src-tauri/Cargo.toml`
- `cargo build --release --manifest-path src-tauri/Cargo.toml`
