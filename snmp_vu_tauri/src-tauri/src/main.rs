#![cfg_attr(not(debug_assertions), windows_subsystem = "windows")]

use serde::{Deserialize, Serialize};
use snmp::{ObjIdBuf, SyncSession, Value};
use std::{
    collections::HashMap,
    fs,
    path::PathBuf,
    process::Command,
    sync::Mutex,
    time::{Duration, SystemTime, UNIX_EPOCH},
};
use tauri::{
    menu::MenuBuilder,
    tray::{MouseButton, MouseButtonState, TrayIconBuilder, TrayIconEvent},
    AppHandle, Emitter, Manager, State, WebviewUrl, WebviewWindowBuilder,
};

const APP_VERSION: &str = env!("CARGO_PKG_VERSION");
const APP_BUILD: &str = "20260316.030500";
const DEFAULT_WIDTH: f64 = 192.0;

#[derive(Debug, Clone, Serialize, Deserialize)]
struct MonitorItem {
    enabled: bool,
    label: String,
    host: String,
    community: String,
    version: String,
    oid: String,
    #[serde(rename = "walkOid", default)]
    walk_oid: String,
    mode: String,
    #[serde(rename = "scaleMbps")]
    scale_mbps: f64,
    #[serde(rename = "pingIp")]
    ping_ip: String,
    #[serde(rename = "alarmEnabled")]
    alarm_enabled: bool,
}

#[derive(Debug, Clone, Serialize, Deserialize)]
struct AppConfig {
    #[serde(rename = "refreshMs")]
    refresh_ms: u64,
    theme: String,
    #[serde(rename = "windowOpacity")]
    window_opacity: f64,
    #[serde(rename = "mainWidth")]
    main_width: f64,
    items: Vec<MonitorItem>,
}

#[derive(Debug, Clone, Serialize)]
struct PollItemResult {
    index: usize,
    label: String,
    enabled: bool,
    mbps: f64,
    percent: f64,
    raw: Option<f64>,
    #[serde(rename = "calcMode")]
    calc_mode: String,
    #[serde(rename = "pingOk")]
    ping_ok: bool,
    #[serde(rename = "pingMs")]
    ping_ms: Option<f64>,
    #[serde(rename = "pingColor")]
    ping_color: String,
    msg: String,
    #[serde(rename = "scaleMbps")]
    scale_mbps: f64,
    #[serde(rename = "alarmEnabled")]
    alarm_enabled: bool,
}

#[derive(Debug, Clone, Serialize)]
struct PollResponse {
    items: Vec<PollItemResult>,
    #[serde(rename = "refreshMs")]
    refresh_ms: u64,
}

#[derive(Debug, Clone, Serialize)]
struct MetaResponse {
    version: String,
    build: String,
}

#[derive(Debug, Clone)]
struct PrevState {
    raw: f64,
    ts_ms: u128,
}

#[derive(Debug, Clone)]
struct PingState {
    orange_streak: u32,
}

struct AppState {
    config: Mutex<AppConfig>,
    previous: Mutex<HashMap<String, PrevState>>,
    ping_state: Mutex<HashMap<String, PingState>>,
}

impl Default for AppConfig {
    fn default() -> Self {
        Self {
            refresh_ms: 3000,
            theme: "blue_ice".into(),
            window_opacity: 1.0,
            main_width: DEFAULT_WIDTH,
            items: (0..5)
                .map(|idx| MonitorItem {
                    enabled: idx == 0,
                    label: format!("VU {}", idx + 1),
                    host: String::new(),
                    community: "public".into(),
                    version: "2c".into(),
                    oid: String::new(),
                    walk_oid: "1.3.6.1.2.1.2.2.1".into(),
                    mode: "auto".into(),
                    scale_mbps: if idx < 4 { 100.0 } else { 300.0 },
                    ping_ip: String::new(),
                    alarm_enabled: false,
                })
                .collect(),
        }
    }
}

fn config_file() -> PathBuf {
    let mut base = dirs::config_dir().unwrap_or_else(|| PathBuf::from("."));
    base.push("palweb-snmp-vu-tauri");
    let _ = fs::create_dir_all(&base);
    base.push("config.json");
    base
}

fn profiles_dir() -> PathBuf {
    let mut base = dirs::config_dir().unwrap_or_else(|| PathBuf::from("."));
    base.push("palweb-snmp-vu-tauri");
    base.push("profiles");
    let _ = fs::create_dir_all(&base);
    base
}

fn load_config() -> AppConfig {
    let file = config_file();
    if let Ok(text) = fs::read_to_string(file) {
        if let Ok(parsed) = serde_json::from_str::<AppConfig>(&text) {
            return normalize_config(parsed);
        }
    }
    AppConfig::default()
}

fn normalize_config(mut cfg: AppConfig) -> AppConfig {
    cfg.refresh_ms = cfg.refresh_ms.max(1000);
    cfg.window_opacity = cfg.window_opacity.clamp(0.45, 1.0);
    cfg.main_width = cfg.main_width.clamp(140.0, 420.0);
    if cfg.items.len() < 5 {
        let defaults = AppConfig::default();
        while cfg.items.len() < 5 {
            cfg.items.push(defaults.items[cfg.items.len()].clone());
        }
    }
    cfg.items.truncate(5);
    for item in &mut cfg.items {
        if item.walk_oid.trim().is_empty() {
            item.walk_oid = "1.3.6.1.2.1.2.2.1".into();
        }
        if item.mode.trim().is_empty() {
            item.mode = "auto".into();
        }
    }
    cfg
}

fn persist_config(cfg: &AppConfig) -> Result<(), String> {
    let text = serde_json::to_string_pretty(cfg).map_err(|e| e.to_string())?;
    fs::write(config_file(), text).map_err(|e| e.to_string())
}

fn safe_profile_name(name: &str) -> String {
    name.chars()
        .filter(|c| c.is_ascii_alphanumeric() || *c == '-' || *c == '_' || *c == ' ')
        .collect::<String>()
        .trim()
        .replace(' ', "_")
}

fn traffic_mode(item: &MonitorItem) -> String {
    if item.mode != "auto" {
        return item.mode.clone();
    }
    let oid = item.oid.as_str();
    if oid.starts_with("1.3.6.1.2.1.2.2.1.10.") || oid.starts_with("1.3.6.1.2.1.2.2.1.16.") {
        return "counter_bytes".into();
    }
    if oid.starts_with("1.3.6.1.2.1.31.1.1.1.6.") || oid.starts_with("1.3.6.1.2.1.31.1.1.1.10.") {
        return "counter_bytes".into();
    }
    "counter_bits".into()
}

fn rate_threshold(mode: &str, scale_mbps: f64) -> f64 {
    if mode == "counter_bytes" {
        scale_mbps.max(1.0) * 1_000_000.0 * 3.0
    } else {
        scale_mbps.max(1.0) * 8_000_000.0 * 3.0
    }
}

fn counter_wrap(item: &MonitorItem, raw: f64) -> f64 {
    if item.oid.starts_with("1.3.6.1.2.1.31.1.1.1.6.") || item.oid.starts_with("1.3.6.1.2.1.31.1.1.1.10.") || raw > 4_294_967_295.0 {
        18_446_744_073_709_551_616.0
    } else {
        4_294_967_296.0
    }
}

fn now_ms() -> u128 {
    SystemTime::now().duration_since(UNIX_EPOCH).unwrap_or_else(|_| Duration::from_secs(0)).as_millis()
}

fn ping_host(ip: &str) -> (bool, Option<f64>) {
    if ip.trim().is_empty() {
        return (false, None);
    }
    let output = if cfg!(target_os = "windows") {
        Command::new("ping").args(["-n", "1", "-w", "1200", ip]).output()
    } else {
        Command::new("ping").args(["-c", "1", "-W", "1", ip]).output()
    };
    let Ok(out) = output else { return (false, None); };
    let text = format!("{}\n{}", String::from_utf8_lossy(&out.stdout), String::from_utf8_lossy(&out.stderr));
    let mut ms = None;
    for token in text.split_whitespace() {
        if let Some(pos) = token.find("time=") {
            let raw = token[pos + 5..].trim_end_matches("ms").replace(',', ".");
            ms = raw.parse::<f64>().ok();
        }
        if let Some(pos) = token.find("tiempo=") {
            let raw = token[pos + 7..].trim_end_matches("ms").replace(',', ".");
            ms = raw.parse::<f64>().ok();
        }
    }
    let ok = out.status.success() || text.to_lowercase().contains("ttl=");
    (ok, ms)
}

fn ping_color(key: &str, ok: bool, ms: Option<f64>, state: &Mutex<HashMap<String, PingState>>) -> String {
    let mut guard = state.lock().unwrap();
    let entry = guard.entry(key.to_string()).or_insert(PingState { orange_streak: 0 });
    let color = if ok {
        match ms.unwrap_or(9999.0) {
            v if v < 100.0 => {
                entry.orange_streak = 0;
                "green"
            }
            v if v < 300.0 => {
                entry.orange_streak = 0;
                "yellow"
            }
            v if v <= 900.0 => {
                entry.orange_streak += 1;
                "orange"
            }
            _ => {
                entry.orange_streak += 1;
                if entry.orange_streak >= 4 { "red" } else { "orange" }
            }
        }
    } else {
        entry.orange_streak += 1;
        if entry.orange_streak >= 4 { "red" } else { "orange" }
    };
    color.to_string()
}

fn parse_oid(oid: &str) -> Result<Vec<u32>, String> {
    let parts = oid
        .split('.')
        .filter(|p| !p.trim().is_empty())
        .map(|p| p.parse::<u32>().map_err(|_| format!("OID invalido: {}", oid)))
        .collect::<Result<Vec<_>, _>>()?;
    if parts.len() < 2 {
        return Err(format!("OID invalido: {}", oid));
    }
    Ok(parts)
}

fn oid_has_prefix(oid: &[u32], prefix: &[u32]) -> bool {
    oid.len() >= prefix.len() && oid[..prefix.len()] == *prefix
}

fn snmp_get_raw(item: &MonitorItem) -> Result<f64, String> {
    if item.host.trim().is_empty() || item.community.trim().is_empty() || item.oid.trim().is_empty() {
        return Err("Faltan host/community/OID".into());
    }
    let timeout = Some(Duration::from_millis(1600));
    let mut sess = SyncSession::new((item.host.as_str(), 161), item.community.as_bytes(), timeout, 0)
        .map_err(|e| format!("{:?}", e))?;
    let oid = parse_oid(&item.oid)?;
    let response = sess.get(&oid).map_err(|e| format!("{:?}", e))?;
    let mut iter = response.varbinds;
    let Some((_oid, value)) = iter.next() else { return Err("Sin respuesta SNMP".into()); };
    let num = match value {
        Value::Integer(v) => v as f64,
        Value::OctetString(_) => return Err("Valor no numerico".into()),
        Value::ObjectIdentifier(_) => return Err("Valor no numerico".into()),
        Value::Null => return Err("Valor nulo".into()),
        Value::Counter32(v) => v as f64,
        Value::Unsigned32(v) => v as f64,
        Value::Timeticks(v) => v as f64,
        Value::Counter64(v) => v as f64,
        _ => return Err("Tipo SNMP no soportado".into()),
    };
    Ok(num)
}

fn snmp_walk_lines(item: &MonitorItem) -> Result<Vec<String>, String> {
    if item.host.trim().is_empty() || item.community.trim().is_empty() || item.walk_oid.trim().is_empty() {
        return Err("Faltan host/community/OID base".into());
    }
    let timeout = Some(Duration::from_millis(1800));
    let mut sess = SyncSession::new((item.host.as_str(), 161), item.community.as_bytes(), timeout, 0)
        .map_err(|e| format!("{:?}", e))?;
    let base = parse_oid(&item.walk_oid)?;
    let mut current = base.clone();
    let mut lines = Vec::new();
    for _ in 0..50 {
        let response = sess.getnext(&current).map_err(|e| format!("{:?}", e))?;
        let mut iter = response.varbinds;
        let Some((name, value)) = iter.next() else { break; };
        let mut buf: ObjIdBuf = [0; 128];
        let next = name.read_name(&mut buf).map_err(|e| format!("{:?}", e))?;
        if !oid_has_prefix(next, &base) {
            break;
        }
        lines.push(format!("{} = {:?}", name, value));
        if next == current.as_slice() {
            break;
        }
        current = next.to_vec();
    }
    if lines.is_empty() {
        Ok(vec!["Sin resultados".into()])
    } else {
        Ok(lines)
    }
}

fn calculate_mbps(item: &MonitorItem, raw: f64, state: &Mutex<HashMap<String, PrevState>>) -> (f64, String) {
    let mode = traffic_mode(item);
    if mode == "direct_mbps" {
        return (raw.max(0.0), "direct_mbps".into());
    }
    if mode == "direct_bps" {
        return (((raw / 8.0) / 1_000_000.0).max(0.0), "direct_bps".into());
    }
    let now = now_ms();
    let mut guard = state.lock().unwrap();
    let prev = guard.insert(item.label.clone(), PrevState { raw, ts_ms: now });
    if raw > 0.0 && raw <= rate_threshold(&mode, item.scale_mbps) {
        if prev.is_none() || prev.as_ref().map(|p| raw < p.raw).unwrap_or(false) {
            return if mode == "counter_bits" {
                (((raw / 8.0) / 1_000_000.0).max(0.0), "auto_direct_bps".into())
            } else {
                ((raw / 1_000_000.0).max(0.0), "auto_direct_bytes".into())
            };
        }
    }
    let Some(prev) = prev else { return (0.0, mode); };
    if now <= prev.ts_ms {
        return (0.0, format!("{}_wait", mode));
    }
    let mut delta = raw - prev.raw;
    let mut calc_mode = mode.clone();
    if delta < 0.0 {
        delta = (counter_wrap(item, raw) - prev.raw) + raw;
        calc_mode = format!("{}_wrap", mode);
    }
    let seconds = ((now - prev.ts_ms) as f64 / 1000.0).max(1.0);
    if mode == "counter_bits" {
        ((((delta / seconds) / 8.0) / 1_000_000.0).max(0.0), calc_mode)
    } else {
        (((delta / seconds) / 1_000_000.0).max(0.0), calc_mode)
    }
}

#[tauri::command]
fn get_meta() -> MetaResponse {
    MetaResponse { version: APP_VERSION.into(), build: APP_BUILD.into() }
}

fn tray_color_from_percent(percent: f64) -> (u8, u8, u8) {
    if percent >= 100.0 {
        (251, 79, 99)
    } else if percent >= 85.0 {
        (251, 146, 60)
    } else if percent >= 60.0 {
        (250, 204, 21)
    } else {
        (50, 214, 105)
    }
}

fn dynamic_tray_image(percent: f64) -> tauri::image::Image<'static> {
    let size = 24u32;
    let (r, g, b) = tray_color_from_percent(percent);
    let mut rgba = vec![0u8; (size * size * 4) as usize];
    let center = (size as f32 - 1.0) / 2.0;
    let radius = 9.5f32;
    for y in 0..size {
        for x in 0..size {
            let idx = ((y * size + x) * 4) as usize;
            let dx = x as f32 - center;
            let dy = y as f32 - center;
            let dist = (dx * dx + dy * dy).sqrt();
            if dist <= radius {
                rgba[idx] = r;
                rgba[idx + 1] = g;
                rgba[idx + 2] = b;
                rgba[idx + 3] = 255;
                if dist >= radius - 1.2 {
                    rgba[idx] = 11;
                    rgba[idx + 1] = 19;
                    rgba[idx + 2] = 32;
                }
            }
        }
    }
    tauri::image::Image::new_owned(rgba, size, size)
}

fn update_tray_tooltip(app: &AppHandle, items: &[PollItemResult]) {
    if let Some(tray) = app.tray_by_id("main-tray") {
        let peak = items.iter().fold(0.0f64, |acc, item| acc.max(item.percent));
        let mut lines = vec!["PalWeb SNMP VU Tauri".to_string()];
        for (idx, item) in items.iter().enumerate() {
            lines.push(format!(
                "VU{}: {:.1} MB/s | {}% | ping {} ms",
                idx + 1,
                item.mbps,
                item.percent.round() as i32,
                item.ping_ms.map(|v| v.round() as i32).unwrap_or(-1)
            ));
        }
        let _ = tray.set_tooltip(Some(lines.join("\n")));
        let _ = tray.set_icon(Some(dynamic_tray_image(peak)));
    }
}

#[tauri::command]
fn get_config(state: State<'_, AppState>) -> Result<AppConfig, String> {
    Ok(state.config.lock().unwrap().clone())
}

#[tauri::command]
fn list_profiles() -> Result<Vec<String>, String> {
    let mut items = Vec::new();
    let entries = fs::read_dir(profiles_dir()).map_err(|e| e.to_string())?;
    for entry in entries {
        let entry = entry.map_err(|e| e.to_string())?;
        let path = entry.path();
        if path.extension().and_then(|x| x.to_str()) == Some("json") {
            if let Some(name) = path.file_stem().and_then(|x| x.to_str()) {
                items.push(name.to_string());
            }
        }
    }
    items.sort();
    Ok(items)
}

#[tauri::command]
fn open_config_window(app: AppHandle) -> Result<bool, String> {
    if let Some(window) = app.get_webview_window("config") {
        let _ = window.show();
        let _ = window.set_focus();
        return Ok(true);
    }
    let window = WebviewWindowBuilder::new(&app, "config", WebviewUrl::App("config.html".into()))
        .title("Configuracion PalWeb SNMP VU Tauri")
        .inner_size(1220.0, 900.0)
        .min_inner_size(960.0, 720.0)
        .resizable(true)
        .maximizable(true)
        .minimizable(true)
        .always_on_top(true)
        .center()
        .build()
        .map_err(|e| e.to_string())?;
    let _ = window.set_focus();
    Ok(true)
}

#[tauri::command]
fn close_app() {
    std::process::exit(0);
}

#[tauri::command]
fn save_config(app: AppHandle, state: State<'_, AppState>, cfg: AppConfig) -> Result<AppConfig, String> {
    let normalized = normalize_config(cfg);
    persist_config(&normalized)?;
    *state.config.lock().unwrap() = normalized.clone();
    if let Some(window) = app.get_webview_window("main") {
        let _ = window.set_size(tauri::Size::Logical(tauri::LogicalSize::new(normalized.main_width, window.inner_size().map(|s| s.height as f64).unwrap_or(980.0))));
    }
    let _ = app.emit("config-updated", &normalized);
    Ok(normalized)
}

#[tauri::command]
fn export_profile(name: String, cfg: AppConfig) -> Result<String, String> {
    let safe = safe_profile_name(&name);
    if safe.is_empty() {
        return Err("Nombre de perfil invalido".into());
    }
    let normalized = normalize_config(cfg);
    let mut path = profiles_dir();
    path.push(format!("{}.json", safe));
    let text = serde_json::to_string_pretty(&normalized).map_err(|e| e.to_string())?;
    fs::write(&path, text).map_err(|e| e.to_string())?;
    Ok(path.to_string_lossy().to_string())
}

#[tauri::command]
fn import_profile(name: String) -> Result<AppConfig, String> {
    let safe = safe_profile_name(&name);
    if safe.is_empty() {
        return Err("Nombre de perfil invalido".into());
    }
    let mut path = profiles_dir();
    path.push(format!("{}.json", safe));
    let text = fs::read_to_string(&path).map_err(|e| e.to_string())?;
    let cfg = serde_json::from_str::<AppConfig>(&text).map_err(|e| e.to_string())?;
    Ok(normalize_config(cfg))
}

#[tauri::command]
fn reset_calc(state: State<'_, AppState>, label: String) -> Result<bool, String> {
    state.previous.lock().unwrap().remove(&label);
    Ok(true)
}

#[tauri::command]
fn poll_items(app: AppHandle, state: State<'_, AppState>) -> Result<PollResponse, String> {
    let cfg = state.config.lock().unwrap().clone();
    let mut results = Vec::new();
    for (index, item) in cfg.items.iter().enumerate() {
        let (ping_ok, ping_ms) = ping_host(&item.ping_ip);
        let ping_color = ping_color(&format!("{}:{}", index, item.label), ping_ok, ping_ms, &state.ping_state);
        let mut result = PollItemResult {
            index,
            label: item.label.clone(),
            enabled: item.enabled,
            mbps: 0.0,
            percent: 0.0,
            raw: None,
            calc_mode: traffic_mode(item),
            ping_ok,
            ping_ms,
            ping_color,
            msg: if item.enabled { "Sin datos".into() } else { "Desactivado".into() },
            scale_mbps: item.scale_mbps,
            alarm_enabled: item.alarm_enabled,
        };
        if !item.enabled {
            results.push(result);
            continue;
        }
        match snmp_get_raw(item) {
            Ok(raw) => {
                let (mbps, calc_mode) = calculate_mbps(item, raw, &state.previous);
                result.raw = Some(raw);
                result.mbps = mbps;
                result.calc_mode = calc_mode;
                result.percent = ((mbps / item.scale_mbps.max(1.0)) * 100.0).clamp(0.0, 100.0);
                result.msg = "OK".into();
            }
            Err(msg) => {
                result.msg = msg;
            }
        }
        results.push(result);
    }
    update_tray_tooltip(&app, &results);
    Ok(PollResponse { items: results, refresh_ms: cfg.refresh_ms })
}

#[tauri::command]
fn snmp_walk(item: MonitorItem) -> Result<String, String> {
    Ok(snmp_walk_lines(&item)?.join("\n"))
}

fn setup_tray(app: &AppHandle) -> Result<(), String> {
    let menu = MenuBuilder::new(app)
        .text("show", "Mostrar")
        .text("hide", "Ocultar")
        .separator()
        .text("quit", "Salir")
        .build()
        .map_err(|e| e.to_string())?;
    let icon = app.default_window_icon().cloned().ok_or_else(|| "No hay icono por defecto".to_string())?;
    TrayIconBuilder::with_id("main-tray")
        .icon(icon)
        .menu(&menu)
        .tooltip("PalWeb SNMP VU Tauri")
        .show_menu_on_left_click(false)
        .on_menu_event(|app, event| {
            if let Some(window) = app.get_webview_window("main") {
                match event.id().as_ref() {
                    "show" => {
                        let _ = window.show();
                        let _ = window.set_focus();
                    }
                    "hide" => {
                        let _ = window.hide();
                    }
                    "quit" => {
                        app.exit(0);
                    }
                    _ => {}
                }
            }
        })
        .on_tray_icon_event(|tray, event| {
            if let TrayIconEvent::Click { button: MouseButton::Left, button_state: MouseButtonState::Up, .. } = event {
                if let Some(window) = tray.app_handle().get_webview_window("main") {
                    let visible = window.is_visible().unwrap_or(true);
                    if visible {
                        let _ = window.hide();
                    } else {
                        let _ = window.show();
                        let _ = window.set_focus();
                    }
                }
            }
        })
        .build(app)
        .map(|_| ())
        .map_err(|e| e.to_string())
}

fn build_state() -> AppState {
    AppState {
        config: Mutex::new(load_config()),
        previous: Mutex::new(HashMap::new()),
        ping_state: Mutex::new(HashMap::new()),
    }
}

fn main() {
    tauri::Builder::default()
        .manage(build_state())
        .setup(|app| {
            if let Some(window) = app.get_webview_window("main") {
                let cfg = app.state::<AppState>().config.lock().unwrap().clone();
                let _ = window.set_size(tauri::Size::Logical(tauri::LogicalSize::new(cfg.main_width, 980.0)));
                let _ = window.set_always_on_top(true);
            }
            setup_tray(app.handle())?;
            Ok(())
        })
        .invoke_handler(tauri::generate_handler![get_meta, get_config, list_profiles, open_config_window, close_app, save_config, export_profile, import_profile, reset_calc, poll_items, snmp_walk])
        .run(tauri::generate_context!())
        .expect("error running tauri app");
}
