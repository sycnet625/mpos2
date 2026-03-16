use std::process::Command;
use std::time::{SystemTime, UNIX_EPOCH};
use std::{fs, path::PathBuf};

fn main() {
    let build = std::env::var("APP_BUILD").unwrap_or_else(|_| generate_build_id());
    let version = std::env::var("APP_VERSION").unwrap_or_else(|_| generate_display_version(&build));
    println!("cargo:rustc-env=APP_BUILD={build}");
    println!("cargo:rustc-env=APP_VERSION={version}");
    write_build_meta(&version, &build);
    tauri_build::build()
}

fn write_build_meta(version: &str, build: &str) {
    let manifest_dir = PathBuf::from(std::env::var("CARGO_MANIFEST_DIR").unwrap_or_else(|_| ".".into()));
    let meta_path = manifest_dir.join("target").join("build-meta.json");
    if let Some(parent) = meta_path.parent() {
        let _ = fs::create_dir_all(parent);
    }
    let payload = format!(
        "{{\n  \"version\": \"{}\",\n  \"build\": \"{}\"\n}}\n",
        version.replace('"', ""),
        build.replace('"', "")
    );
    let _ = fs::write(meta_path, payload);
}

fn generate_build_id() -> String {
    if cfg!(target_os = "windows") {
        if let Ok(output) = Command::new("powershell")
            .args([
                "-NoProfile",
                "-Command",
                "Get-Date -Format yyyyMMdd.HHmmss",
            ])
            .output()
        {
            if output.status.success() {
                let text = String::from_utf8_lossy(&output.stdout).trim().to_string();
                if !text.is_empty() {
                    return text;
                }
            }
        }
    } else if let Ok(output) = Command::new("date").arg("+%Y%m%d.%H%M%S").output() {
        if output.status.success() {
            let text = String::from_utf8_lossy(&output.stdout).trim().to_string();
            if !text.is_empty() {
                return text;
            }
        }
    }

    let seconds = SystemTime::now()
        .duration_since(UNIX_EPOCH)
        .map(|d| d.as_secs())
        .unwrap_or(0);
    format!("unix.{seconds}")
}

fn generate_display_version(build: &str) -> String {
    let cargo_version = std::env::var("CARGO_PKG_VERSION").unwrap_or_else(|_| "1.0.0".to_string());
    let mut parts = cargo_version.split('.');
    let major = parts.next().unwrap_or("1");
    let minor = parts.next().unwrap_or("0");
    let patch_digits: String = build.chars().filter(|c| c.is_ascii_digit()).collect();
    let patch = if patch_digits.is_empty() { "0" } else { patch_digits.trim_start_matches('0') };
    let patch = if patch.is_empty() { "0" } else { patch };
    format!("{}.{}.{}", major, minor, patch)
}
