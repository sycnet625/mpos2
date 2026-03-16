use std::process::Command;
use std::time::{SystemTime, UNIX_EPOCH};

fn main() {
    let build = std::env::var("APP_BUILD").unwrap_or_else(|_| generate_build_id());
    println!("cargo:rustc-env=APP_BUILD={build}");
    tauri_build::build()
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
