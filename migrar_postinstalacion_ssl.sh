#!/usr/bin/env bash
set -euo pipefail

# Post-instalación SSL para el servidor destino.
# Uso: migrar_postinstalacion_ssl.sh dominios.com,www.dominios.com correo@dominios.com

DOMAIN_SPEC="${1:-${MIRROR_SSL_DOMAIN:-}}"
EMAIL="${2:-${MIRROR_SSL_EMAIL:-}}"
IFS=',' read -r -a DOMAINS <<< "$DOMAIN_SPEC"

[[ -n "$DOMAIN_SPEC" ]] || { echo "Falta el dominio SSL" >&2; exit 2; }
[[ "$EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { echo "Correo SSL inválido" >&2; exit 2; }
(( ${#DOMAINS[@]} > 0 )) || { echo "No se indicó ningún dominio SSL" >&2; exit 2; }

for domain in "${DOMAINS[@]}"; do
  [[ "$domain" =~ ^[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?(\.[A-Za-z0-9]([A-Za-z0-9-]{0,61}[A-Za-z0-9])?)+$ ]] || {
    echo "Dominio SSL inválido: $domain" >&2
    exit 2
  }
done

command -v certbot >/dev/null 2>&1 || { echo "No se encontró certbot en el destino" >&2; exit 1; }
command -v nginx >/dev/null 2>&1 || { echo "No se encontró nginx en el destino" >&2; exit 1; }

primary_domain="${DOMAINS[0]}"
server_names="$(printf '%s ' "${DOMAINS[@]}")"
server_names="${server_names% }"
cert_dir="/etc/letsencrypt/live/$primary_domain"
config_file="/etc/nginx/sites-available/palweb-ssl.conf"
enabled_link="/etc/nginx/sites-enabled/palweb-ssl.conf"
public_origin="https://$primary_domain"
backup_dir="/root/palweb-postinstall-backups/$(date +%Y%m%d_%H%M%S)"

mkdir -p "$backup_dir"
chmod 700 "$backup_dir"

backup_file() {
  local source="$1"
  [[ -e "$source" || -L "$source" ]] || return 0
  cp -a -- "$source" "$backup_dir/$(basename "$source")"
}

update_application_config() {
  local cfg_file="/var/www/pos.cfg"
  [[ -f "$cfg_file" ]] || {
    echo "WARNING|No existe $cfg_file; se omite actualización de configuración"
    return 0
  }

  backup_file "$cfg_file"
  PALWEB_PRIMARY_DOMAIN="$primary_domain" PALWEB_DOMAIN_LIST="$DOMAIN_SPEC" php -r '
    $path = $argv[1];
    $raw = @file_get_contents($path);
    $cfg = json_decode((string)$raw, true);
    if (!is_array($cfg)) { fwrite(STDERR, "pos.cfg no contiene JSON válido\n"); exit(1); }
    $primary = trim((string)getenv("PALWEB_PRIMARY_DOMAIN"));
    $domains = array_values(array_filter(array_map("trim", explode(",", (string)getenv("PALWEB_DOMAIN_LIST")))));
    $origin = "https://" . $primary;
    $cfg["website"] = $origin;
    if (array_key_exists("sitio_web", $cfg)) $cfg["sitio_web"] = $origin;
    $cfg["ticket_qr_url_base"] = $origin;
    $cfg["public_domains"] = $domains;

    $oldToNew = [
        "www.palweb.net" => $primary,
        "palweb.net" => $primary,
        "shop.palweb.net" => $domains[1] ?? $primary,
        "palweb.mooo.com" => $primary,
    ];
    if (isset($cfg["subdomain_sucursal_map"]) && is_array($cfg["subdomain_sucursal_map"])) {
        foreach ($cfg["subdomain_sucursal_map"] as &$entry) {
            if (!is_array($entry)) continue;
            $host = strtolower(trim((string)($entry["host"] ?? "")));
            if (isset($oldToNew[$host])) $entry["host"] = $oldToNew[$host];
        }
        unset($entry);
    }

    $mode = @fileperms($path);
    $tmp = $path . ".postinstall." . getmypid();
    $json = json_encode($cfg, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    if ($json === false || @file_put_contents($tmp, $json, LOCK_EX) === false) { fwrite(STDERR, "No se pudo escribir pos.cfg temporal\n"); exit(1); }
    if ($mode !== false) @chmod($tmp, $mode & 07777);
    if (!@rename($tmp, $path)) { @unlink($tmp); fwrite(STDERR, "No se pudo activar el nuevo pos.cfg\n"); exit(1); }
  ' "$cfg_file"
  echo "CONFIG_POST_INSTALL_OK|pos.cfg actualizado para $public_origin"
}

update_static_public_files() {
  local robots="/var/www/robots.txt"
  if [[ -f "$robots" ]]; then
    backup_file "$robots"
    if grep -q '^Sitemap:' "$robots"; then
      sed -E -i "s#^Sitemap:.*#Sitemap: ${public_origin}/marinero/sitemap.xml#" "$robots"
    else
      printf '\nSitemap: %s/marinero/sitemap.xml\n' "$public_origin" >> "$robots"
    fi
  fi
  echo "CONFIG_POST_INSTALL|robots.txt y sitemap apuntan a $public_origin"
}

refresh_pwa_cache() {
  local stamp="migration-$(date +%Y%m%d%H%M%S)"
  local file
  for file in /var/www/service-worker.js /var/www/sw-products.js; do
    [[ -f "$file" ]] || continue
    backup_file "$file"
    if [[ "$(basename "$file")" == "service-worker.js" ]]; then
      sed -E -i \
        -e "s#(const SW_VERSION[[:space:]]*=[[:space:]]*')[^']*#\\1$stamp#" \
        -e "s#(const CACHE_NAME[[:space:]]*=[[:space:]]*')[^']*#\\1palweb-pos-$stamp#" \
        "$file"
    else
      sed -E -i "s#(const CACHE_NAME[[:space:]]*=[[:space:]]*')[^']*#\\1marinero-products-$stamp#" "$file"
    fi
  done
  echo "PWA_POST_INSTALL|Versiones de caché renovadas: $stamp"
}

audit_database_domain_references() {
  local report="$backup_dir/domain-audit.txt"
  if ! command -v php >/dev/null 2>&1 || [[ ! -f /var/www/db.php ]]; then
    echo "WARNING|No se pudo ejecutar la auditoría de URLs en la base de datos" | tee "$report"
    return 0
  fi
  PALWEB_AUDIT_REPORT="$report" php -r '
    $report = (string)getenv("PALWEB_AUDIT_REPORT");
    $old = ["www.palweb.net", "shop.palweb.net", "palweb.net", "palweb.mooo.com"];
    try {
      require "/var/www/db.php";
      if (!isset($pdo) || !($pdo instanceof PDO)) throw new RuntimeException("PDO no disponible");
      $columns = $pdo->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND DATA_TYPE IN (\"char\",\"varchar\",\"text\",\"tinytext\",\"mediumtext\",\"longtext\")")->fetchAll(PDO::FETCH_ASSOC);
      $lines = ["Auditoría de referencias al dominio anterior (solo lectura)", "Generado: " . date("c"), ""];
      foreach ($columns as $column) {
        $table = (string)$column["TABLE_NAME"]; $name = (string)$column["COLUMN_NAME"];
        if (!preg_match("/^[A-Za-z0-9_]+$/", $table . $name)) continue;
        $parts = [];
        foreach ($old as $domain) {
          $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE `{$name}` LIKE ?");
          $st->execute(["%" . $domain . "%"]);
          $count = (int)$st->fetchColumn();
          if ($count > 0) $parts[] = $domain . "=" . $count;
        }
        if ($parts) $lines[] = $table . "." . $name . " | " . implode(", ", $parts);
      }
      if (count($lines) === 3) $lines[] = "Sin referencias encontradas.";
      @file_put_contents($report, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX);
    } catch (Throwable $e) {
      @file_put_contents($report, "No se pudo auditar la BD: " . $e->getMessage() . PHP_EOL, LOCK_EX);
    }
  '
  echo "CONFIG_POST_INSTALL|Auditoría de URLs de BD: $report"
}

write_manual_checklist() {
  local checklist="$backup_dir/manual-checklist.txt"
  {
    echo "Post-instalación PalWeb"
    echo "Dominio principal: $public_origin"
    echo "Dominios SSL: $DOMAIN_SPEC"
    echo
    echo "Revisar manualmente en Meta/Facebook: App Domains, OAuth redirect URIs y Webhooks."
    echo "Revisar integraciones de pagos, Telegram, correo y cualquier callback externo."
    echo "Si existe sync_client.php en otro equipo, cambiar REMOTE_URL a: $public_origin/api/api_sync_server.php"
    echo "Auditoría de BD: $backup_dir/domain-audit.txt"
    echo "Respaldo de pos.cfg: $backup_dir/pos.cfg"
  } > "$checklist"
  chmod 600 "$checklist"
  echo "CONFIG_POST_INSTALL|Lista de comprobación: $checklist"
}

configure_php_fpm() {
  if ! command -v php >/dev/null 2>&1; then
    echo "WARNING|PHP no está instalado en el destino"
    return 0
  fi
  local php_version fpm_service
  php_version="$(php -r 'echo PHP_MAJOR_VERSION, ".", PHP_MINOR_VERSION;')"
  if command -v apt-get >/dev/null 2>&1; then
    export DEBIAN_FRONTEND=noninteractive
    apt-get install -y "php${php_version}-pgsql" "php${php_version}-snmp" >/dev/null
  fi
  fpm_service="php${php_version}-fpm"
  if systemctl list-unit-files "${fpm_service}.service" >/dev/null 2>&1; then
    systemctl enable "${fpm_service}.service" >/dev/null 2>&1 || true
    systemctl restart "${fpm_service}.service"
    sed -E -i "s#unix:/run/php/php[0-9.]+-fpm\\.sock#unix:/run/php/php${php_version}-fpm.sock#g" \
      "$config_file" "/etc/nginx/sites-available/palweb" 2>/dev/null || true
    nginx -t >/dev/null
    systemctl reload nginx
    echo "PHP_POST_INSTALL_OK|PHP-FPM activo: $fpm_service"
  else
    echo "WARNING|No se encontró $fpm_service; verificar el socket configurado en Nginx"
  fi
  for module in pgsql snmp; do
    php -m 2>/dev/null | grep -qx "$module" || echo "WARNING|Extensión PHP ausente: $module"
  done
}

update_nginx_templates() {
  local template="/etc/nginx/sites-available/palweb"
  [[ -f "$template" ]] || return 0
  backup_file "$template"
  sed -E -i \
    -e '/^[[:space:]]*if[[:space:]]*\(\$host[[:space:]]*=/,+2d' \
    -e "s@^[[:space:]]*server_name[[:space:]].*;@    server_name $server_names;@" \
    -e "s@^[[:space:]]*ssl_certificate_key[[:space:]]+[^;]+;.*@    ssl_certificate_key $cert_dir/privkey.pem;@" \
    -e "s@^[[:space:]]*ssl_certificate[[:space:]]+[^;]+;.*@    ssl_certificate $cert_dir/fullchain.pem;@" \
    "$template"
  echo "NGINX_POST_INSTALL|Plantilla palweb actualizada"
}

enable_certbot_renewal() {
  if systemctl list-unit-files certbot.timer >/dev/null 2>&1; then
    systemctl enable --now certbot.timer >/dev/null 2>&1 || true
  fi
  if certbot renew --dry-run >/dev/null 2>&1; then
    echo "SSL_POST_INSTALL_OK|Renovación Certbot verificada"
  else
    echo "WARNING|certbot renew --dry-run falló; revisar DNS, firewall y timer"
  fi
}

update_bridge_env() {
  local env_file="/var/www/wa_web_bridge/.env"
  [[ -f "$env_file" ]] || return 0
  backup_file "$env_file"
  local escaped_origin
  escaped_origin="$(printf '%s' "$public_origin" | sed 's/[\\&|]/\\&/g')"
  sed -E -i \
    -e "s|^(POS_BOT_HOST=).*|\\1$primary_domain|" \
    -e "s|^(POS_BOT_PUBLIC_ORIGIN=).*|\\1$escaped_origin|" \
    -e "s|^(POS_BOT_API_BASE=).*|\\1$escaped_origin|" \
    "$env_file"
  echo "WA_POST_INSTALL|.env del bridge actualizado; el servicio genérico permanece deshabilitado"
}

configure_facebook_bot() {
  local setup_script="/var/www/setup_fb_bot_remote.sh"
  local unit="/etc/systemd/system/palweb-fb-novnc.service"
  if [[ ! -f "$setup_script" || ! -f "$unit" ]]; then
    echo "FB_POST_INSTALL|No se detectó instalación de Facebook/noVNC; se omite"
    return 0
  fi
  echo "FB_POST_INSTALL|Actualizando gateway noVNC y ruta pública"
  MIRROR_PUBLIC_ORIGIN="$public_origin" bash "$setup_script"
}

echo "SSL_POST_INSTALL|Solicitando certificado para: $server_names"
was_active=0
if systemctl is-active --quiet nginx; then
  was_active=1
  systemctl stop nginx
fi

certbot_args=(certonly --standalone --preferred-challenges http --non-interactive --agree-tos --keep-until-expiring --email "$EMAIL" --cert-name "$primary_domain")
for domain in "${DOMAINS[@]}"; do
  certbot_args+=(-d "$domain")
done

if ! certbot "${certbot_args[@]}"; then
  echo "ERROR: Certbot no pudo emitir el certificado. Verifica DNS y puertos 80/443." >&2
  if (( was_active )); then systemctl start nginx || true; fi
  exit 1
fi

[[ -r "$cert_dir/fullchain.pem" && -r "$cert_dir/privkey.pem" ]] || {
  echo "ERROR: Certbot terminó pero no creó los certificados esperados en $cert_dir" >&2
  if (( was_active )); then systemctl start nginx || true; fi
  exit 1
}

template=""
for candidate in /etc/nginx/sites-disabled/palweb.migration-disabled /etc/nginx/sites-available/palweb; do
  if [[ -f "$candidate" ]]; then
    template="$candidate"
    break
  fi
done

if [[ -n "$template" ]]; then
  backup_file "$config_file"
  cp "$template" "$config_file"
  sed -E -i \
    -e '/^[[:space:]]*if[[:space:]]*\(\$host[[:space:]]*=/,+2d' \
    -e "s@^[[:space:]]*server_name[[:space:]].*;@    server_name $server_names;@" \
    -e "s@^[[:space:]]*ssl_certificate_key[[:space:]]+[^;]+;.*@    ssl_certificate_key $cert_dir/privkey.pem;@" \
    -e "s@^[[:space:]]*ssl_certificate[[:space:]]+[^;]+;.*@    ssl_certificate $cert_dir/fullchain.pem;@" \
    "$config_file"
  if ! grep -q 'options-ssl-nginx.conf' "$config_file" && [[ -r /etc/letsencrypt/options-ssl-nginx.conf ]]; then
    sed -i "/ssl_certificate_key/a\\    include /etc/letsencrypt/options-ssl-nginx.conf;" "$config_file"
  fi
  if ! grep -q 'ssl-dhparams.pem' "$config_file" && [[ -r /etc/letsencrypt/ssl-dhparams.pem ]]; then
    sed -i "/ssl_certificate_key/a\\    ssl_dhparam /etc/letsencrypt/ssl-dhparams.pem;" "$config_file"
  fi
else
  php_sock="$(find /run/php -maxdepth 1 -type s -name 'php*-fpm.sock' -print -quit 2>/dev/null || true)"
  php_pass="127.0.0.1:9000"
  [[ -z "$php_sock" ]] || php_pass="unix:$php_sock"
  cat > "$config_file" <<EOF
server {
    listen 80;
    listen [::]:80;
    server_name $server_names;
    return 301 https://\$host\$request_uri;
}
server {
    listen 443 ssl http2;
    listen [::]:443 ssl http2;
    server_name $server_names;
    root /var/www;
    index index.php index.html;
    client_max_body_size 512M;
    ssl_certificate $cert_dir/fullchain.pem;
    ssl_certificate_key $cert_dir/privkey.pem;
    include /etc/letsencrypt/options-ssl-nginx.conf;
    location / {
        try_files \$uri \$uri/ =404;
    }
    location ~ \\.php\$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass $php_pass;
    }
    location ~ /\\. {
        deny all;
    }
}
EOF
fi

mkdir -p /etc/nginx/sites-disabled /etc/nginx/sites-enabled
for cfg in /etc/nginx/sites-enabled/*; do
  [[ -e "$cfg" ]] || continue
  [[ "$cfg" == "$enabled_link" ]] && continue
  if grep -Eiq '/etc/letsencrypt|listen[[:space:]].*443[[:space:]].*ssl|palweb\.net|palweb\.mooo\.com' "$cfg" 2>/dev/null; then
    disabled="/etc/nginx/sites-disabled/$(basename "$cfg").migration-disabled"
    rm -f "$disabled"
    mv "$cfg" "$disabled"
  fi
done

rm -f /etc/nginx/conf.d/palweb-migration-http.conf "$enabled_link"
ln -s "$config_file" "$enabled_link"

if ! nginx -t; then
  echo "ERROR: La configuración SSL de nginx no es válida" >&2
  if (( was_active )); then systemctl start nginx || true; fi
  exit 1
fi

systemctl enable nginx >/dev/null 2>&1 || true
systemctl start nginx
echo "SSL_POST_INSTALL_OK|Nginx activo con HTTPS para: $server_names"

# El código PHP y los ficheros estáticos deben usar el dominio nuevo incluso
# cuando el despliegue proviene de una copia antigua del servidor origen.
update_application_config
update_static_public_files
refresh_pwa_cache
update_nginx_templates
configure_php_fpm
enable_certbot_renewal
update_bridge_env
configure_facebook_bot
audit_database_domain_references
write_manual_checklist

# Configura una instancia del bridge para el dominio principal. Las sesiones
# .wwebjs_auth no se migran: el primer arranque en el destino solicitará QR.
bridge_dir="/var/www/wa_web_bridge"
bridge_template="/etc/systemd/system/palweb-wa-bridge@.service"
if [[ -f "$bridge_template" && -f "$bridge_dir/bridge.js" ]]; then
  bridge_unit="palweb-wa-bridge@${primary_domain}.service"
  instance_dir="$bridge_dir/instances/$primary_domain"
  install -d -o www-data -g www-data -m 0750 \
    "$bridge_dir/instances" "$instance_dir" "$instance_dir/runtime" \
    "$instance_dir/.cache" "$instance_dir/.config" "$instance_dir/.wwebjs_auth"
  chown -R www-data:www-data "$instance_dir"

  systemctl daemon-reload
  if systemctl is-active --quiet palweb-wa-bridge.service 2>/dev/null || systemctl is-enabled --quiet palweb-wa-bridge.service 2>/dev/null; then
    systemctl disable --now palweb-wa-bridge.service >/dev/null 2>&1 || true
    echo "WA_POST_INSTALL|Bridge genérico deshabilitado para evitar una segunda instancia"
  fi
  for old_link in /etc/systemd/system/multi-user.target.wants/palweb-wa-bridge@*.service; do
    [[ -e "$old_link" ]] || continue
    old_unit="$(basename "$old_link")"
    [[ "$old_unit" == "$bridge_unit" ]] && continue
    systemctl disable --now "$old_unit" >/dev/null 2>&1 || true
    echo "WA_POST_INSTALL|Instancia anterior deshabilitada: $old_unit"
  done

  systemctl enable "$bridge_unit" >/dev/null
  systemctl restart "$bridge_unit"
  if systemctl is-active --quiet "$bridge_unit"; then
    echo "WA_POST_INSTALL_OK|Bridge activo: $bridge_unit"
    echo "WA_POST_INSTALL|Escanea el QR desde journalctl -u $bridge_unit -f"
  else
    echo "ERROR: El bridge no quedó activo; consulta journalctl -u $bridge_unit -n 80 --no-pager" >&2
    systemctl status "$bridge_unit" --no-pager -l || true
    exit 1
  fi
else
  echo "WA_POST_INSTALL|Bridge no encontrado; se omitió su configuración"
fi
