$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$TauriDir = Join-Path $ProjectRoot "src-tauri"
$DistDir = Join-Path $ProjectRoot "dist-win"

Write-Host "== PalWeb SNMP VU Tauri | Build Windows bundle ==" -ForegroundColor Cyan

if (-not (Get-Command cargo -ErrorAction SilentlyContinue)) {
    throw "Rust/Cargo no esta instalado. Instala Rustup primero."
}

if (-not (Get-Command npm -ErrorAction SilentlyContinue)) {
    throw "Node.js/NPM no esta instalado."
}

if (-not (Get-Command cargo-tauri -ErrorAction SilentlyContinue)) {
    Write-Host "Instalando cargo-tauri..." -ForegroundColor Yellow
    cargo install tauri-cli --version "^2"
}

Set-Location $ProjectRoot

if (-not (Test-Path $DistDir)) {
    New-Item -ItemType Directory -Path $DistDir | Out-Null
}

Write-Host "Compilando bundle Windows..." -ForegroundColor Cyan
cargo tauri build --bundles nsis,msi

$BundleRoot = Join-Path $TauriDir "target\release\bundle"
$NsisDir = Join-Path $BundleRoot "nsis"
$MsiDir = Join-Path $BundleRoot "msi"

if (Test-Path $NsisDir) {
    Copy-Item (Join-Path $NsisDir "*") $DistDir -Force
}
if (Test-Path $MsiDir) {
    Copy-Item (Join-Path $MsiDir "*") $DistDir -Force
}

Write-Host "Artefactos copiados a: $DistDir" -ForegroundColor Green
