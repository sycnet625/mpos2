#!/usr/bin/env bash
set -euo pipefail

REPO_DIR="/var/www"
PROFILE_DIR="/var/www/fb_bot_browser_profile"
DISPLAY_NUM=":99"
VNC_PORT="5909"
NOVNC_PORT="6080"
PHP_FPM_SERVICE="${PHP_FPM_SERVICE:-php8.3-fpm}"
PUBLIC_ORIGIN="${MIRROR_PUBLIC_ORIGIN:-}"
NGINX_SITE_CANDIDATES=(
  "/etc/nginx/sites-enabled/palweb-ssl.conf"
  "/etc/nginx/sites-enabled/palweb"
  "/etc/nginx/sites-available/palweb"
  "/etc/nginx/sites-enabled/default"
)

require_root() {
  if [[ "${EUID}" -ne 0 ]]; then
    echo "Este script debe correr como root." >&2
    exit 1
  fi
}

detect_public_origin() {
  if [[ -n "${PUBLIC_ORIGIN}" ]]; then
    echo "${PUBLIC_ORIGIN%/}"
    return 0
  fi
  if [[ -f "${REPO_DIR}/pos.cfg" ]] && command -v php >/dev/null 2>&1; then
    local configured
    configured="$(php -r 'require $argv[1]; echo function_exists("config_loader_public_origin") ? config_loader_public_origin() : "";' "${REPO_DIR}/config_loader.php" 2>/dev/null || true)"
    if [[ -n "${configured}" && "${configured}" != "https://localhost" ]]; then
      echo "${configured%/}"
      return 0
    fi
  fi
  local host
  host="$(hostname -f 2>/dev/null || hostname 2>/dev/null || true)"
  [[ -n "${host}" ]] || host="localhost"
  echo "https://${host}"
}

detect_novnc_web() {
  local candidates=(
    "/usr/share/novnc"
    "/usr/share/novnc/"
    "/usr/share/novnc/utils/../"
  )
  local path
  for path in "${candidates[@]}"; do
    if [[ -d "${path}" && -f "${path%/}/vnc_lite.html" ]]; then
      echo "${path%/}"
      return 0
    fi
  done
  echo ""
}

install_packages() {
  export DEBIAN_FRONTEND=noninteractive
  apt-get update
  apt-get install -y xvfb x11vnc novnc websockify chromium-browser || \
  apt-get install -y xvfb x11vnc novnc websockify chromium
}

prepare_dirs() {
  mkdir -p "${PROFILE_DIR}/.config" "${PROFILE_DIR}/.cache" /tmp/palweb-fb-runtime
  chown -R www-data:www-data "${PROFILE_DIR}"
  chown -R www-data:www-data /tmp/palweb-fb-runtime
}

write_systemd_units() {
  local novnc_web
  novnc_web="$(detect_novnc_web)"
  if [[ -z "${novnc_web}" ]]; then
    echo "No se encontró la carpeta web de noVNC en /usr/share/novnc." >&2
    exit 1
  fi

  cat > /etc/systemd/system/palweb-fb-display.service <<EOF
[Unit]
Description=PalWeb Facebook virtual display
After=network.target

[Service]
Type=simple
User=www-data
Group=www-data
Environment=HOME=/var/www
ExecStart=/usr/bin/Xvfb ${DISPLAY_NUM} -screen 0 1440x900x24 -ac +extension GLX +render -noreset
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

  cat > /etc/systemd/system/palweb-fb-x11vnc.service <<EOF
[Unit]
Description=PalWeb Facebook x11vnc server
After=palweb-fb-display.service
Requires=palweb-fb-display.service

[Service]
Type=simple
User=www-data
Group=www-data
Environment=HOME=/var/www
ExecStart=/usr/bin/x11vnc -display ${DISPLAY_NUM} -forever -shared -rfbport ${VNC_PORT} -localhost -nopw
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

  cat > /etc/systemd/system/palweb-fb-novnc.service <<EOF
[Unit]
Description=PalWeb Facebook noVNC proxy
After=palweb-fb-x11vnc.service
Requires=palweb-fb-x11vnc.service

[Service]
Type=simple
User=www-data
Group=www-data
Environment=HOME=/var/www
ExecStart=/usr/bin/websockify --web=${novnc_web} ${NOVNC_PORT} 127.0.0.1:${VNC_PORT}
Restart=always
RestartSec=2

[Install]
WantedBy=multi-user.target
EOF

  systemctl daemon-reload
  systemctl enable --now palweb-fb-display.service
  systemctl enable --now palweb-fb-x11vnc.service
  systemctl enable --now palweb-fb-novnc.service
}

detect_nginx_site() {
  local f
  for f in "${NGINX_SITE_CANDIDATES[@]}"; do
    if [[ -f "${f}" ]]; then
      echo "${f}"
      return 0
    fi
  done
  echo ""
}

ensure_nginx_location() {
  local site_file
  site_file="$(detect_nginx_site)"
  if [[ -z "${site_file}" ]]; then
    echo "No se encontró archivo de sitio Nginx candidato." >&2
    exit 1
  fi

  if grep -q "location /fbnovnc/" "${site_file}"; then
    return 0
  fi

  cp "${site_file}" "${site_file}.bak.$(date +%Y%m%d%H%M%S)"
  python3 - <<PY
from pathlib import Path
path = Path(${site_file@Q})
text = path.read_text()
block = """
    location /fbnovnc/ {
        proxy_pass http://127.0.0.1:${NOVNC_PORT}/;
        proxy_http_version 1.1;
        proxy_set_header Host \$host;
        proxy_set_header X-Real-IP \$remote_addr;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_set_header Upgrade \$http_upgrade;
        proxy_set_header Connection "upgrade";
        proxy_read_timeout 86400;
    }
"""
idx = text.rfind("}")
if idx == -1:
    raise SystemExit("No se encontró cierre de bloque en el sitio Nginx")
text = text[:idx] + block + "\n" + text[idx:]
path.write_text(text)
PY

  nginx -t
  systemctl reload nginx
}

reload_php() {
  systemctl reload "${PHP_FPM_SERVICE}"
}

validate_repo_files() {
  php -l "${REPO_DIR}/fb_bot.php"
  php -l "${REPO_DIR}/fb_bot_api.php"
  php -l "${REPO_DIR}/fb_bot_worker.php"
  node --check "${REPO_DIR}/fb_group_login.js"
  node --check "${REPO_DIR}/fb_group_scraper.js"
}

show_status() {
  echo
  echo "Servicios:"
  systemctl is-active palweb-fb-display.service || true
  systemctl is-active palweb-fb-x11vnc.service || true
  systemctl is-active palweb-fb-novnc.service || true
  echo
  echo "Visor:"
  echo "$(detect_public_origin)/fbnovnc/vnc_lite.html?autoconnect=1&resize=remote&path=fbnovnc/websockify"
}

main() {
  require_root
  install_packages
  prepare_dirs
  write_systemd_units
  ensure_nginx_location
  reload_php
  validate_repo_files
  show_status
}

main "$@"
