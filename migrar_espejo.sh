#!/usr/bin/env bash
set -euo pipefail

# Migración espejo de PalWeb POS a otro servidor Linux.
# El modo predeterminado es dry-run. La migración real requiere --mode run.
#
# Uso recomendado:
#   MIRROR_SSHPASS='clave' ./migrar_espejo.sh --dest root@IP_DESTINO
# La contraseña se lee desde MIRROR_SSHPASS/SSHPASS para que no aparezca en ps.

DEST=""
SSH_PORT="22"
MODE="dry-run"
SYNC_USERS="yes"
SYNC_IMAGES="yes"
SYNC_CONFIGS="yes"
DB_SCOPE="app"
ALLOW_DELETE="no"
TARGET_MODE="update"
SSH_PASSWORD="${MIRROR_SSHPASS:-${SSHPASS:-}}"
SETUP_SSL="${MIRROR_SETUP_SSL:-no}"
SSL_DOMAIN="${MIRROR_SSL_DOMAIN:-}"
SSL_EMAIL="${MIRROR_SSL_EMAIL:-}"
ONLY_STEP=""

DB_PHP_PATH="/var/www/db.php"
PRODUCT_IMAGES_PATH="/home/marinero/product_images"
MYSQL_DUMP_REMOTE_FILE="/tmp/palweb_migration.sql"
MYSQL_CNF_REMOTE_FILE="/tmp/palweb_migration.cnf"
MYSQL_USER_SQL_REMOTE_FILE="/tmp/palweb_migration_user.sql"
POST_INSTALL_SSL_SCRIPT="/var/www/migrar_postinstalacion_ssl.sh"
POST_INSTALL_SSL_REMOTE_FILE="/tmp/palweb_migrar_postinstalacion_ssl.sh"
RSYNC_FLAGS_BASE="-aHAX --numeric-ids --info=progress2"
RSYNC_FLAGS_MYSQL="$RSYNC_FLAGS_BASE --exclude=debian.cnf"
RSYNC_EXCLUDES_WWW="--exclude=.pos-mobile-secrets/ --exclude=.ssh/ --exclude=.git/ --exclude=.agents/ --exclude=.claude/ --exclude=.aider* --exclude=wa_web_bridge/node_modules/ --exclude=wa_web_bridge/.wwebjs_auth/ --exclude=wa_web_bridge/.wwebjs_cache/ --exclude=wa_web_bridge/.cache/ --exclude=wa_web_bridge/.config/ --exclude=wa_web_bridge/instances/*/.wwebjs_auth/ --exclude=wa_web_bridge/instances/*/.cache/ --exclude=wa_web_bridge/instances/*/.config/ --exclude=snmp_vu_tauri/src-tauri/target/ --exclude=wa_web_bridge/instances/*/runtime/"
TAR_EXCLUDES_WWW=(
  '--exclude=var/www/.pos-mobile-secrets'
  '--exclude=var/www/.ssh'
  '--exclude=var/www/.git'
  '--exclude=var/www/.agents'
  '--exclude=var/www/.claude'
  '--exclude=var/www/.aider*'
  '--exclude=var/www/wa_web_bridge/node_modules'
  '--exclude=var/www/wa_web_bridge/.wwebjs_auth'
  '--exclude=var/www/wa_web_bridge/.wwebjs_cache'
  '--exclude=var/www/wa_web_bridge/.cache'
  '--exclude=var/www/wa_web_bridge/.config'
  '--exclude=var/www/wa_web_bridge/instances/*/.wwebjs_auth'
  '--exclude=var/www/wa_web_bridge/instances/*/.cache'
  '--exclude=var/www/wa_web_bridge/instances/*/.config'
  '--exclude=var/www/snmp_vu_tauri/src-tauri/target'
  '--exclude=var/www/wa_web_bridge/instances/*/runtime'
)
RSYNC_FLAGS_WWW="$RSYNC_FLAGS_BASE $RSYNC_EXCLUDES_WWW"
MIRROR_RUN_ID="$(date +%Y%m%d_%H%M%S)_$$"
REMOTE_BACKUP_DIR="/root/palweb-migration-backups/$MIRROR_RUN_ID"
REMOTE_ARCHIVE_FILE="/tmp/palweb_initial_${MIRROR_RUN_ID}.tar.zst"
CONTROL_PATH="/tmp/palweb_mirror_ssh_${RANDOM}_%r@%h:%p"
KNOWN_HOSTS_FILE="${MIRROR_KNOWN_HOSTS_FILE:-/tmp/palweb_mirror_known_hosts_$MIRROR_RUN_ID}"
TEMP_FILES=()
if [[ -z "${MIRROR_KNOWN_HOSTS_FILE:-}" ]]; then
  TEMP_FILES+=("$KNOWN_HOSTS_FILE")
fi

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
err() { printf '[%s] ERROR: %s\n' "$(date '+%F %T')" "$*" >&2; }
step_start() { printf 'STEP_START:%s:%s\n' "$1" "$2"; }
step_done() { printf 'STEP_DONE:%s\n' "$1"; }

usage() {
  cat <<USAGE
Uso:
  MIRROR_SSHPASS='clave' $0 --dest root@IP_DESTINO [opciones]

Opciones:
  --dest              Destino SSH, por ejemplo root@203.0.113.10
  --ssh-port          Puerto SSH (default: 22)
  --mode              dry-run (default) o run
  --target-mode       new | update (default: update). new instala Ubuntu 24.04 LTS
                      y usa tar+zstd para la primera copia; update usa rsync incremental.
  --sync-users        yes (default) | no; agrega usuarios/grupos locales
  --sync-images       yes (default) | no; copia /home/marinero/product_images
  --sync-configs      yes (default) | no; copia configuraciones de servicios
  --db-scope          app (default) | all; solo BD de db.php o todas las BDs
  --allow-delete      yes | no (default); habilita --delete solo en /var/www
  --setup-ssl         yes | no (default); ejecuta el post-instalador de Certbot
                      usando MIRROR_SSL_DOMAIN y MIRROR_SSL_EMAIL
  --ssh-password      Compatibilidad antigua; no usar en producción porque puede
                      quedar visible en la lista de procesos. Preferir MIRROR_SSHPASS.
  --only-step         Ejecuta solo un paso (1..10) o all (default: todos)

Pasos:
  1 Instalar stack Ubuntu 24.04 LTS en servidor nuevo
  2 Preflight remoto, espacio, permisos y respaldo previo
  3 Sincronizar /var/www y las imágenes fuera del webroot
  4 Instalar dependencias de aplicación (Node/WhatsApp)
  5 Sincronizar nginx, PHP, Apache, MySQL y certificados
  6 Sincronizar systemd, cron y recargar unidades
  7 Agregar usuarios/grupos con UID/GID >= 1000
  8 Dump y restore de la BD de la aplicación
  9 Validar configuración y reiniciar servicios
  10 Smoke test final: PHP, nginx, MySQL, archivos e imágenes
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dest) DEST="${2:-}"; shift 2 ;;
    --ssh-port) SSH_PORT="${2:-}"; shift 2 ;;
    --mode) MODE="${2:-}"; shift 2 ;;
    --target-mode) TARGET_MODE="${2:-}"; shift 2 ;;
    --sync-users) SYNC_USERS="${2:-}"; shift 2 ;;
    --sync-images) SYNC_IMAGES="${2:-}"; shift 2 ;;
    --sync-configs) SYNC_CONFIGS="${2:-}"; shift 2 ;;
    --db-scope) DB_SCOPE="${2:-}"; shift 2 ;;
    --allow-delete) ALLOW_DELETE="${2:-}"; shift 2 ;;
    --setup-ssl) SETUP_SSL="${2:-}"; shift 2 ;;
    --ssl-domain) SSL_DOMAIN="${2:-}"; shift 2 ;;
    --ssl-email) SSL_EMAIL="${2:-}"; shift 2 ;;
    --ssh-password)
      SSH_PASSWORD="${2:-}"
      err "--ssh-password está obsoleto; preferir MIRROR_SSHPASS para no exponerla en ps."
      shift 2
      ;;
    --only-step) ONLY_STEP="${2:-}"; shift 2 ;;
    -h|--help) usage; exit 0 ;;
    *) err "Parámetro no reconocido: $1"; usage; exit 1 ;;
  esac
done

[[ -n "$DEST" ]] || { err "Debes indicar --dest"; usage; exit 1; }
[[ "$DEST" =~ ^[a-zA-Z0-9._-]+@[a-zA-Z0-9._-]+$ ]] || { err "--dest debe tener formato usuario@host"; exit 1; }
[[ "$SSH_PORT" =~ ^[0-9]{1,5}$ ]] || { err "--ssh-port inválido"; exit 1; }
(( SSH_PORT >= 1 && SSH_PORT <= 65535 )) || { err "--ssh-port debe estar entre 1 y 65535"; exit 1; }
[[ "$MODE" == "dry-run" || "$MODE" == "run" ]] || { err "--mode debe ser dry-run o run"; exit 1; }
[[ "$TARGET_MODE" == "new" || "$TARGET_MODE" == "update" ]] || { err "--target-mode debe ser new o update"; exit 1; }
[[ "$SYNC_USERS" == "yes" || "$SYNC_USERS" == "no" ]] || { err "--sync-users debe ser yes o no"; exit 1; }
[[ "$SYNC_IMAGES" == "yes" || "$SYNC_IMAGES" == "no" ]] || { err "--sync-images debe ser yes o no"; exit 1; }
[[ "$SYNC_CONFIGS" == "yes" || "$SYNC_CONFIGS" == "no" ]] || { err "--sync-configs debe ser yes o no"; exit 1; }
[[ "$DB_SCOPE" == "app" || "$DB_SCOPE" == "all" ]] || { err "--db-scope debe ser app o all"; exit 1; }
[[ "$ALLOW_DELETE" == "yes" || "$ALLOW_DELETE" == "no" ]] || { err "--allow-delete debe ser yes o no"; exit 1; }
[[ "$SETUP_SSL" == "yes" || "$SETUP_SSL" == "no" ]] || { err "MIRROR_SETUP_SSL debe ser yes o no"; exit 1; }
if [[ "$SETUP_SSL" == "yes" ]]; then
  [[ -f "$POST_INSTALL_SSL_SCRIPT" ]] || { err "No existe $POST_INSTALL_SSL_SCRIPT"; exit 1; }
  [[ "$SSL_DOMAIN" =~ ^[A-Za-z0-9.-]+(,[A-Za-z0-9.-]+)*$ ]] || { err "MIRROR_SSL_DOMAIN inválido"; exit 1; }
  [[ "$SSL_EMAIL" =~ ^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$ ]] || { err "MIRROR_SSL_EMAIL inválido"; exit 1; }
fi
if [[ -n "$ONLY_STEP" && "$ONLY_STEP" != "all" && ! "$ONLY_STEP" =~ ^(10|[1-9])$ ]]; then
  err "--only-step debe estar entre 1 y 10, o ser all"
  exit 1
fi

if [[ "$ALLOW_DELETE" == "yes" ]]; then
  RSYNC_FLAGS_WWW="$RSYNC_FLAGS_BASE $RSYNC_EXCLUDES_WWW --delete"
fi

SSH_OPTS=(
  -p "$SSH_PORT"
  -o StrictHostKeyChecking=accept-new
  -o UserKnownHostsFile="$KNOWN_HOSTS_FILE"
  -o GlobalKnownHostsFile=/dev/null
  -F /dev/null
  -o ControlMaster=auto
  -o ControlPath="$CONTROL_PATH"
  -o ControlPersist=30m
)

ssh_exec() {
  local remote_cmd="$1"
  if [[ "$MODE" == "dry-run" ]]; then
    log "[DRY-RUN] ssh ${SSH_OPTS[*]} $DEST '$remote_cmd'"
    return 0
  fi
  if [[ -n "$SSH_PASSWORD" ]]; then
    SSHPASS="$SSH_PASSWORD" sshpass -e ssh "${SSH_OPTS[@]}" "$DEST" "$remote_cmd"
  else
    ssh "${SSH_OPTS[@]}" "$DEST" "$remote_cmd"
  fi
}

rsync_exec() {
  local flags="$1" src="$2" dst="$3"
  local rsh="ssh -p $SSH_PORT -o StrictHostKeyChecking=accept-new -o UserKnownHostsFile=$KNOWN_HOSTS_FILE -o GlobalKnownHostsFile=/dev/null -F /dev/null -o ControlMaster=auto -o ControlPath=$CONTROL_PATH -o ControlPersist=30m"
  [[ -n "$SSH_PASSWORD" ]] && rsh="sshpass -e $rsh"

  if [[ "$MODE" == "dry-run" ]]; then
    log "rsync --dry-run $flags '$src' '$dst'"
    if [[ -n "$SSH_PASSWORD" ]]; then
      SSHPASS="$SSH_PASSWORD" rsync --dry-run $flags -e "$rsh" "$src" "$dst"
    else
      rsync --dry-run $flags -e "$rsh" "$src" "$dst"
    fi
  else
    log "rsync $flags '$src' '$dst'"
    if [[ -n "$SSH_PASSWORD" ]]; then
      SSHPASS="$SSH_PASSWORD" rsync $flags -e "$rsh" "$src" "$dst"
    else
      rsync $flags -e "$rsh" "$src" "$dst"
    fi
  fi
}

emit_pv_progress() {
  local phase="$1" percent
  while IFS= read -r percent; do
    [[ "$percent" =~ ^[0-9]+([.][0-9]+)?$ ]] || continue
    percent="${percent%%.*}"
    (( percent > 100 )) && percent=100
    # Este callback se ejecuta dentro de pipelines pv|zstd y pv|ssh.
    # El stdout debe quedar reservado para los bytes del archivo.
    printf 'MIGRATION_PROGRESS|%s|%s\n' "$phase" "$percent" >&2
  done
}

emit_remote_progress() {
  local phase="$1" line
  while IFS= read -r line; do
    if [[ "$line" =~ ^[0-9]+([.][0-9]+)?$ ]]; then
      emit_pv_progress "$phase" <<<"$line"
    elif [[ -n "$line" ]]; then
      printf '%s\n' "$line" >&2
    fi
  done
}

archive_source_bytes() {
  local bytes
  bytes="$(find /var/www -xdev \
    \( -path '/var/www/.pos-mobile-secrets' \
      -o -path '/var/www/.ssh' \
      -o -path '/var/www/.git' \
      -o -path '/var/www/.agents' \
      -o -path '/var/www/.claude' \
      -o -path '/var/www/.aider*' \
      -o -path '/var/www/wa_web_bridge/node_modules' \
      -o -path '/var/www/wa_web_bridge/.wwebjs_auth' \
      -o -path '/var/www/wa_web_bridge/.wwebjs_cache' \
      -o -path '/var/www/wa_web_bridge/.cache' \
      -o -path '/var/www/wa_web_bridge/.config' \
      -o -path '/var/www/wa_web_bridge/instances/*/.wwebjs_auth' \
      -o -path '/var/www/wa_web_bridge/instances/*/.cache' \
      -o -path '/var/www/wa_web_bridge/instances/*/.config' \
      -o -path '/var/www/snmp_vu_tauri/src-tauri/target' \
      -o -path '/var/www/wa_web_bridge/instances/*/runtime' \) -prune \
    -o -type f -printf '%s\n' | awk '{total += $1} END {printf "%.0f\n", total + 0}')"
  if [[ "$SYNC_IMAGES" == "yes" && -d "$PRODUCT_IMAGES_PATH" ]]; then
    bytes=$((bytes + $(find "$PRODUCT_IMAGES_PATH" -xdev -type f -printf '%s\n' | awk '{total += $1} END {printf "%.0f\n", total + 0}')))
  fi
  (( bytes > 0 )) || bytes=1
  printf '%s\n' "$bytes"
}

archive_exec() {
  local include_images="$1"
  local transfer_label="/var/www"
  local source_bytes tar_path archive_path tar_bytes archive_bytes
  local remote_cmd network_code decompress_code
  local -a tar_args=("${TAR_EXCLUDES_WWW[@]}" --numeric-owner --acls --xattrs --sparse -C / -cpf - var/www)
  if [[ "$include_images" == "yes" && -d "$PRODUCT_IMAGES_PATH" ]]; then
    tar_args+=(home/marinero/product_images)
    transfer_label="/var/www e imágenes"
  fi

  if [[ "$MODE" == "dry-run" ]]; then
    log "[DRY-RUN] tar+zstd inicial medible de $transfer_label | progreso: tar, zstd, red y descompresión"
    return 0
  fi

  source_bytes="$(archive_source_bytes)"
  tar_path="/tmp/palweb_initial_${MIRROR_RUN_ID}.tar"
  archive_path="/tmp/palweb_initial_${MIRROR_RUN_ID}.tar.zst"
  TEMP_FILES+=("$tar_path" "$archive_path")

  log "Copia inicial tar+zstd de $transfer_label (tamaño fuente estimado: $source_bytes bytes)"
  printf 'MIGRATION_PROGRESS|tar|0\n'
  tar "${tar_args[@]}" | pv -f -n -i 1 -s "$source_bytes" 2> >(emit_pv_progress tar) > "$tar_path"
  tar_bytes="$(stat -c '%s' "$tar_path")"
  printf 'MIGRATION_PROGRESS|tar|100\n'
  log "Archivo tar creado: $tar_bytes bytes"

  printf 'MIGRATION_PROGRESS|zstd|0\n'
  pv -f -n -i 1 -s "$tar_bytes" "$tar_path" 2> >(emit_pv_progress zstd) | zstd -T0 -3 -q -o "$archive_path"
  archive_bytes="$(stat -c '%s' "$archive_path")"
  printf 'MIGRATION_PROGRESS|zstd|100\n'
  log "Archivo tar.zst creado: $archive_bytes bytes"

  remote_cmd="bash -lc 'set -euo pipefail; mkdir -p /var/www /home/marinero/product_images; zstd -d -q $REMOTE_ARCHIVE_FILE -c | pv -f -n -i 1 -s $tar_bytes | tar --numeric-owner -xpf - -C /; rm -f $REMOTE_ARCHIVE_FILE'"

  log "Iniciando envío por red: $archive_bytes bytes hacia $DEST"
  printf 'MIGRATION_PROGRESS|network|0\n'
  if [[ -n "$SSH_PASSWORD" ]]; then
    if pv -f -n -i 1 -s "$archive_bytes" "$archive_path" 2> >(emit_pv_progress network) \
      | SSHPASS="$SSH_PASSWORD" sshpass -e ssh "${SSH_OPTS[@]}" "$DEST" "cat > '$REMOTE_ARCHIVE_FILE'"; then
      network_code=0
    else
      network_code=$?
    fi
  else
    if pv -f -n -i 1 -s "$archive_bytes" "$archive_path" 2> >(emit_pv_progress network) \
      | ssh "${SSH_OPTS[@]}" "$DEST" "cat > '$REMOTE_ARCHIVE_FILE'"; then
      network_code=0
    else
      network_code=$?
    fi
  fi
  if (( network_code != 0 )); then
    err "Falló el envío por red de $archive_path hacia $DEST (código $network_code)"
    return "$network_code"
  fi
  printf 'MIGRATION_PROGRESS|network|100\n'
  log "Envío de red completado: $archive_bytes bytes"

  printf 'MIGRATION_PROGRESS|decompress|0\n'
  log "Iniciando descompresión y restauración remota"
  if [[ -n "$SSH_PASSWORD" ]]; then
    if SSHPASS="$SSH_PASSWORD" sshpass -e ssh "${SSH_OPTS[@]}" "$DEST" "$remote_cmd" 2> >(emit_remote_progress decompress); then
      decompress_code=0
    else
      decompress_code=$?
    fi
  else
    if ssh "${SSH_OPTS[@]}" "$DEST" "$remote_cmd" 2> >(emit_remote_progress decompress); then
      decompress_code=0
    else
      decompress_code=$?
    fi
  fi
  if (( decompress_code != 0 )); then
    err "Falló la descompresión/restauración remota (código $decompress_code)"
    return "$decompress_code"
  fi
  printf 'MIGRATION_PROGRESS|decompress|100\n'
}

ensure_local_requirements() {
  for bin in rsync ssh mysqldump getent awk php; do
    command -v "$bin" >/dev/null 2>&1 || { err "Falta comando local: $bin"; exit 1; }
  done
  if [[ "$TARGET_MODE" == "new" ]]; then
    for bin in tar zstd pv; do
      command -v "$bin" >/dev/null 2>&1 || { err "Falta comando local para la copia inicial: $bin"; exit 1; }
    done
  fi
  if [[ -n "$SSH_PASSWORD" ]]; then
    command -v sshpass >/dev/null 2>&1 || { err "Falta sshpass para autenticación por contraseña"; exit 1; }
  fi
  if [[ "$SETUP_SSL" == "yes" && ! -f "$POST_INSTALL_SSL_SCRIPT" ]]; then
    err "No existe el script post-instalación SSL: $POST_INSTALL_SSL_SCRIPT"
    exit 1
  fi
  mkdir -p "$(dirname "$KNOWN_HOSTS_FILE")"
  touch "$KNOWN_HOSTS_FILE"
  chmod 600 "$KNOWN_HOSTS_FILE" 2>/dev/null || true
}

load_mysql_from_dbphp() {
  [[ -f "$DB_PHP_PATH" ]] || { err "No existe $DB_PHP_PATH para leer credenciales MySQL"; return 1; }
  local parsed
  parsed="$(php -r '
    $f = $argv[1]; $s = @file_get_contents($f);
    if ($s === false) exit(2);
    foreach (["host","db","user","pass","port"] as $k) {
      if (preg_match("/\\$".$k."\\s*=\\s*\\x27([^\\x27]*)\\x27\\s*;/", $s, $m)) echo $k."=".$m[1].PHP_EOL;
    }
  ' "$DB_PHP_PATH")"

  MYSQL_HOST="$(printf '%s\n' "$parsed" | awk -F= '$1=="host"{print $2}')"
  MYSQL_DB="$(printf '%s\n' "$parsed" | awk -F= '$1=="db"{print $2}')"
  MYSQL_USER="$(printf '%s\n' "$parsed" | awk -F= '$1=="user"{print $2}')"
  MYSQL_PASS="$(printf '%s\n' "$parsed" | awk -F= '$1=="pass"{print $2}')"
  MYSQL_PORT="$(printf '%s\n' "$parsed" | awk -F= '$1=="port"{print $2}')"
  [[ -z "${MYSQL_HOST:-}" ]] && MYSQL_HOST="127.0.0.1"
  [[ -z "${MYSQL_PORT:-}" ]] && MYSQL_PORT="3306"
  [[ -n "${MYSQL_DB:-}" && -n "${MYSQL_USER:-}" ]] || { err "Credenciales incompletas en $DB_PHP_PATH"; return 1; }
}

mysql_sql_quote() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\'/\'\'}"
  printf "'%s'" "$value"
}

mysql_identifier() {
  local value="$1"
  value="${value//\`/\`\`}"
  printf '%s' "$value"
}

start_ssh_master() {
  [[ "$MODE" == "dry-run" ]] && { log "[DRY-RUN] Se omite apertura de conexión SSH persistente"; return 0; }
  log "Abriendo conexión SSH persistente"
  if [[ -n "$SSH_PASSWORD" ]]; then
    SSHPASS="$SSH_PASSWORD" sshpass -e ssh "${SSH_OPTS[@]}" "$DEST" "echo ok" >/dev/null
  else
    ssh "${SSH_OPTS[@]}" "$DEST" "echo ok" >/dev/null
  fi
}

cleanup_ssh_master() {
  [[ "$MODE" == "run" ]] || return 0
  if [[ -n "$SSH_PASSWORD" ]]; then
    SSHPASS="$SSH_PASSWORD" sshpass -e ssh -O exit "${SSH_OPTS[@]}" "$DEST" >/dev/null 2>&1 || true
  else
    ssh -O exit "${SSH_OPTS[@]}" "$DEST" >/dev/null 2>&1 || true
  fi
}

cleanup_temp_files() {
  local file
  for file in "${TEMP_FILES[@]:-}"; do
    [[ -z "$file" ]] || rm -f -- "$file" 2>/dev/null || true
  done
}

cleanup_all() {
  cleanup_ssh_master
  cleanup_temp_files
}
trap cleanup_all EXIT

step1_bootstrap_remote() {
  step_start 1 "Instalar stack Ubuntu 24.04 LTS"
  ssh_exec "set -eu
emit_status() { printf 'MIGRATION_STATUS|%s|%s|%s\\n' \"\$1\" \"\$2\" \"\$3\"; }
check_command() {
  if command -v \"\$2\" >/dev/null 2>&1; then emit_status \"\$1\" ok \"Disponible\"; return 0; fi
  emit_status \"\$1\" failed \"No se encontró el comando \$2\"; return 1
}
check_service() {
  if systemctl is-active --quiet \"\$2\"; then emit_status \"\$1\" ok \"Servicio activo\"; return 0; fi
  emit_status \"\$1\" failed \"Servicio no activo: \$2\"; return 1
}
test \"\$(id -u)\" -eq 0 || { echo 'El destino debe aceptar SSH como root'; exit 1; }
test -r /etc/os-release || { echo 'No se pudo identificar el sistema operativo remoto'; exit 1; }
. /etc/os-release
emit_status ubuntu checking \"Sistema detectado: \$PRETTY_NAME\"
for software in nginx certbot mysql php-fpm node npm composer rsync; do emit_status \"\$software\" pending \"Preparando instalación/verificación\"; done
if [ '$TARGET_MODE' = new ]; then
  [ \"\$ID\" = ubuntu ] && [ \"\$VERSION_ID\" = 24.04 ] || { emit_status ubuntu failed \"Se requiere Ubuntu 24.04 LTS; detectado \$PRETTY_NAME\"; exit 1; }
  emit_status ubuntu ok \"Ubuntu 24.04 LTS confirmado\"
  export DEBIAN_FRONTEND=noninteractive
  emit_status apt installing \"Actualizando índices de paquetes\"
  if ! apt-get update; then emit_status apt failed \"apt-get update falló\"; exit 1; fi
  emit_status apt installing \"Instalando nginx, Certbot, MySQL, PHP y herramientas\"
  if ! apt-get install -y ca-certificates curl gnupg lsb-release software-properties-common apt-transport-https \
    nginx certbot python3-certbot-nginx mysql-server mysql-client rsync tar zstd pv unzip git composer cron acl \
    php8.3 php8.3-cli php8.3-fpm php8.3-common php8.3-mysql php8.3-pgsql php8.3-snmp php8.3-curl php8.3-mbstring php8.3-xml \
    php8.3-zip php8.3-gd php8.3-intl php8.3-bcmath php8.3-opcache php8.3-soap \
    build-essential libnss3 libatk-bridge2.0-0 libgtk-3-0 libgbm1 libasound2t64 libxshmfence1 \
    libxdamage1 libxrandr2 libxkbcommon0 libxcomposite1 libxfixes3 libdrm2 libx11-xcb1 libcups2 fonts-liberation; then
    emit_status apt failed \"No se pudieron instalar los paquetes base\"; exit 1
  fi
  emit_status apt ok \"Paquetes base instalados\"
  node_major=0
  if command -v node >/dev/null 2>&1; then node_major=\$(node -p \"process.versions.node.split(String.fromCharCode(46))[0]\"); fi
  if [ \"\$node_major\" -lt 22 ]; then
    emit_status node installing \"Configurando repositorio Node.js 22\"
    key_tmp=\$(mktemp)
    if ! curl -fsSL https://deb.nodesource.com/gpgkey/nodesource-repo.gpg.key > \"\$key_tmp\"; then emit_status node failed \"No se pudo descargar la clave NodeSource\"; exit 1; fi
    install -d -m 0755 /etc/apt/keyrings
    if ! gpg --dearmor < \"\$key_tmp\" > /etc/apt/keyrings/nodesource.gpg; then emit_status node failed \"No se pudo instalar la clave NodeSource\"; exit 1; fi
    rm -f \"\$key_tmp\"
    echo 'deb [signed-by=/etc/apt/keyrings/nodesource.gpg] https://deb.nodesource.com/node_22.x nodistro main' > /etc/apt/sources.list.d/nodesource.list
    if ! apt-get update || ! apt-get install -y nodejs; then emit_status node failed \"No se pudo instalar Node.js 22\"; exit 1; fi
  fi
  emit_status services installing \"Activando nginx, PHP-FPM, MySQL y cron\"
  if ! systemctl enable --now nginx php8.3-fpm cron; then emit_status services failed \"No se pudieron activar nginx/PHP-FPM/cron\"; exit 1; fi
  if ! systemctl enable --now mysql 2>/dev/null && ! systemctl enable --now mariadb 2>/dev/null; then emit_status services failed \"No se pudo activar MySQL/MariaDB\"; exit 1; fi
  emit_status services ok \"Servicios base activos\"
  install -d -o www-data -g www-data -m 0755 /var/www /home/marinero/product_images
fi
failed=0
check_command nginx nginx || failed=1
check_command certbot certbot || failed=1
check_command mysql mysql || failed=1
check_service php-fpm php8.3-fpm || failed=1
if command -v node >/dev/null 2>&1; then
  node_major_check=\$(node -p \"process.versions.node.split(String.fromCharCode(46))[0]\")
  if [ \"\$node_major_check\" -ge 22 ]; then emit_status node ok \"Node.js \$(node --version)\"; else emit_status node failed \"Se requiere Node.js 22; detectado \$(node --version)\"; failed=1; fi
else
  emit_status node failed \"No se encontró el comando node\"; failed=1
fi
check_command npm npm || failed=1
check_command composer composer || failed=1
check_command rsync rsync || failed=1
if [ \"\$failed\" -ne 0 ]; then emit_status stack failed \"Faltan componentes del stack remoto\"; exit 1; fi
emit_status stack ok \"Stack remoto verificado\"
php -v | head -1
node --version
mysql --version | head -1"
  step_done 1
}

step2_prepare_remote() {
  step_start 2 "Preflight remoto y respaldo"
  ssh_exec "set -eu; test \"\$(id -u)\" -eq 0 || { echo 'El destino debe aceptar SSH como root'; exit 1; }; for b in rsync tar systemctl php mysql; do command -v \"\$b\" >/dev/null || { echo \"Falta comando remoto: \$b\"; exit 1; }; done; if [ '$TARGET_MODE' = new ]; then for b in zstd pv; do command -v \"\$b\" >/dev/null || { echo \"Falta comando remoto: \$b\"; exit 1; }; done; fi; mkdir -p /var/www '$REMOTE_BACKUP_DIR'; df -Pk /var/www /tmp"
  if [[ "$MODE" == "run" && "$SYNC_CONFIGS" == "yes" ]]; then
    ssh_exec "set -eu; mkdir -p '$REMOTE_BACKUP_DIR'; for item in nginx php apache2 mysql letsencrypt; do if [ -e \"/etc/\$item\" ]; then tar -czf '$REMOTE_BACKUP_DIR/etc_\$item.tgz' -C / etc/\$item; fi; done; chmod 700 '$REMOTE_BACKUP_DIR'"
  fi
  step_done 2
}

step3_sync_www() {
  step_start 3 "Sincronizar aplicación y datos"
  if [[ "$TARGET_MODE" == "new" ]]; then
    archive_exec "$SYNC_IMAGES"
  else
    rsync_exec "$RSYNC_FLAGS_WWW" "/var/www/" "$DEST:/var/www/"
    if [[ "$SYNC_IMAGES" == "yes" && -d "$PRODUCT_IMAGES_PATH" ]]; then
      rsync_exec "$RSYNC_FLAGS_BASE" "$PRODUCT_IMAGES_PATH/" "$DEST:$PRODUCT_IMAGES_PATH/"
    else
      log "No se sincronizan imágenes externas (opción no o ruta inexistente)"
    fi
  fi
  step_done 3
}

step4_install_app_dependencies() {
  step_start 4 "Instalar dependencias de aplicación"
  ssh_exec 'set -eu
if command -v apt-get >/dev/null 2>&1 && command -v php >/dev/null 2>&1; then
  php_version="$(php -r "echo PHP_MAJOR_VERSION, \".\", PHP_MINOR_VERSION;")"
  export DEBIAN_FRONTEND=noninteractive
  apt-get install -y "php${php_version}-pgsql" "php${php_version}-snmp"
fi
if [ -f /var/www/wa_web_bridge/package-lock.json ]; then
  cd /var/www/wa_web_bridge
  npm ci --omit=dev
fi
if [ -f /var/www/composer.json ]; then
  cd /var/www
  composer install --no-dev --no-interaction --prefer-dist
fi
mkdir -p /var/www/wa_web_bridge/.cache/puppeteer /home/marinero/product_images
chown -R www-data:www-data /var/www/wa_web_bridge/.cache /home/marinero/product_images 2>/dev/null || true'
  step_done 4
}

step5_sync_configs() {
  step_start 5 "Sincronizar configuraciones"
  if [[ "$SYNC_CONFIGS" != "yes" ]]; then
    log "Saltando configuraciones (--sync-configs no)"
    step_done 5
    return 0
  fi
  if [[ -d /etc/nginx ]]; then rsync_exec "$RSYNC_FLAGS_BASE" /etc/nginx/ "$DEST:/etc/nginx/"; else log "Saltando /etc/nginx"; fi
  if [[ -d /etc/php ]]; then rsync_exec "$RSYNC_FLAGS_BASE" /etc/php/ "$DEST:/etc/php/"; else log "Saltando /etc/php"; fi
  if [[ -d /etc/apache2 ]]; then rsync_exec "$RSYNC_FLAGS_BASE" /etc/apache2/ "$DEST:/etc/apache2/"; else log "Saltando /etc/apache2"; fi
  if [[ -d /etc/mysql ]]; then
    # debian.cnf es un archivo local generado por el paquete y normalmente es
    # 0600 root:root; el trabajador web no puede leerlo y no debe trasladarse
    # porque puede contener credenciales internas de la instalación origen.
    log "Omitiendo /etc/mysql/debian.cnf (credencial local protegida; se genera en el destino)"
    rsync_exec "$RSYNC_FLAGS_MYSQL" /etc/mysql/ "$DEST:/etc/mysql/"
  else
    log "Saltando /etc/mysql"
  fi
  if [[ -d /etc/letsencrypt && -r /etc/letsencrypt && -x /etc/letsencrypt && -r /etc/letsencrypt/live && -x /etc/letsencrypt/live ]]; then
    rsync_exec "$RSYNC_FLAGS_BASE" /etc/letsencrypt/ "$DEST:/etc/letsencrypt/"
  else
    log "AVISO: se omite /etc/letsencrypt porque sus certificados son privados de root; se debe usar Certbot en el destino o copiarlo manualmente como root."
  fi
  step_done 5
}

step6_sync_systemd_cron() {
  step_start 6 "Sincronizar systemd y cron"
  if [[ -d /etc/systemd/system ]]; then rsync_exec "$RSYNC_FLAGS_BASE" /etc/systemd/system/ "$DEST:/etc/systemd/system/"; else log "Saltando systemd"; fi
  if [[ -d /etc/cron.d ]]; then rsync_exec "$RSYNC_FLAGS_BASE" /etc/cron.d/ "$DEST:/etc/cron.d/"; else log "Saltando cron.d"; fi
  local cron_tmp
  cron_tmp="$(mktemp /tmp/palweb_cron.root.XXXXXX)"
  TEMP_FILES+=("$cron_tmp")
  if crontab -l >"$cron_tmp" 2>/dev/null; then
    rsync_exec "$RSYNC_FLAGS_BASE" "$cron_tmp" "$DEST:/root/cron.root.txt"
    ssh_exec "crontab /root/cron.root.txt"
  else
    log "No hay crontab de root local"
  fi
  ssh_exec "systemctl daemon-reload"
  rm -f "$cron_tmp"
  step_done 6
}

step7_sync_users_groups() {
  step_start 7 "Sincronizar usuarios y grupos"
  if [[ "$SYNC_USERS" != "yes" ]]; then
    log "Saltando usuarios/grupos (--sync-users no)"
    step_done 7
    return 0
  fi
  local groups_tmp users_tmp
  groups_tmp="$(mktemp /tmp/palweb_groups.XXXXXX)"
  users_tmp="$(mktemp /tmp/palweb_users.XXXXXX)"
  TEMP_FILES+=("$groups_tmp" "$users_tmp")
  getent group | awk -F: '$3>=1000 && $1!="nogroup"{print}' > "$groups_tmp"
  getent passwd | awk -F: '$3>=1000 && $1!="nobody"{print}' > "$users_tmp"
  rsync_exec "$RSYNC_FLAGS_BASE" "$groups_tmp" "$DEST:/root/mirror_groups.txt"
  rsync_exec "$RSYNC_FLAGS_BASE" "$users_tmp" "$DEST:/root/mirror_users.txt"
  ssh_exec 'set -eu
while IFS=: read -r name _ gid _; do
  getent group "$name" >/dev/null && continue
  if getent group "$gid" >/dev/null; then echo "GID ocupado, se omite grupo $name ($gid)" >&2; continue; fi
  groupadd -g "$gid" "$name"
done < /root/mirror_groups.txt
while IFS=: read -r name _ uid gid gecos home shell; do
  id -u "$name" >/dev/null 2>&1 && continue
  if getent passwd "$uid" >/dev/null; then echo "UID ocupado, se omite usuario $name ($uid)" >&2; continue; fi
  useradd -u "$uid" -g "$gid" -d "$home" -s "$shell" -c "$gecos" -M "$name"
done < /root/mirror_users.txt'
  rm -f "$groups_tmp" "$users_tmp"
  step_done 7
}

step8_mysql_dump_restore() {
  step_start 8 "Dump y restore de MySQL"
  local dump_tmp mysql_cnf_tmp mysql_user_sql_tmp mysql_err_tmp
  load_mysql_from_dbphp
  [[ "$MYSQL_DB" =~ ^[A-Za-z0-9_$-]+$ ]] || { err "Nombre de BD no seguro para provisionar: $MYSQL_DB"; return 1; }
  [[ "$MYSQL_USER" =~ ^[A-Za-z0-9_$-]+$ ]] || { err "Usuario MySQL no seguro para provisionar: $MYSQL_USER"; return 1; }
  dump_tmp="$(mktemp /tmp/palweb_migration.XXXXXX.sql)"
  mysql_cnf_tmp="$(mktemp /tmp/palweb_mysql.XXXXXX.cnf)"
  mysql_user_sql_tmp="$(mktemp /tmp/palweb_mysql_user.XXXXXX.sql)"
  mysql_err_tmp="$(mktemp /tmp/palweb_mysqldump.XXXXXX.log)"
  TEMP_FILES+=("$dump_tmp" "$mysql_cnf_tmp" "$mysql_user_sql_tmp" "$mysql_err_tmp")
  cat > "$mysql_cnf_tmp" <<EOF
[client]
host=$MYSQL_HOST
port=$MYSQL_PORT
user=$MYSQL_USER
password=$MYSQL_PASS
EOF
  chmod 600 "$mysql_cnf_tmp"

  local mysql_db_ident mysql_user_literal mysql_pass_literal
  mysql_db_ident="$(mysql_identifier "$MYSQL_DB")"
  mysql_user_literal="$(mysql_sql_quote "$MYSQL_USER")"
  mysql_pass_literal="$(mysql_sql_quote "$MYSQL_PASS")"
  cat > "$mysql_user_sql_tmp" <<EOF
CREATE DATABASE IF NOT EXISTS \`$mysql_db_ident\`;
CREATE USER IF NOT EXISTS $mysql_user_literal@'localhost' IDENTIFIED BY $mysql_pass_literal;
ALTER USER $mysql_user_literal@'localhost' IDENTIFIED BY $mysql_pass_literal;
GRANT ALL PRIVILEGES ON \`$mysql_db_ident\`.* TO $mysql_user_literal@'localhost';
CREATE USER IF NOT EXISTS $mysql_user_literal@'127.0.0.1' IDENTIFIED BY $mysql_pass_literal;
ALTER USER $mysql_user_literal@'127.0.0.1' IDENTIFIED BY $mysql_pass_literal;
GRANT ALL PRIVILEGES ON \`$mysql_db_ident\`.* TO $mysql_user_literal@'127.0.0.1';
FLUSH PRIVILEGES;
EOF
  chmod 600 "$mysql_user_sql_tmp"

  local dump_args=("--defaults-extra-file=$mysql_cnf_tmp" --routines --triggers --events --single-transaction)
  if [[ "$DB_SCOPE" == "all" ]]; then
    dump_args+=(--all-databases)
  else
    dump_args+=(--databases "$MYSQL_DB")
  fi
  if [[ "$MODE" == "dry-run" ]]; then
    log "[DRY-RUN] mysqldump de alcance $DB_SCOPE; no se lee ni restaura la BD"
  else
    if ! mysqldump "${dump_args[@]}" > "$dump_tmp" 2>"$mysql_err_tmp"; then
      if [[ "$DB_SCOPE" == "all" ]]; then
        err "Falló el dump de todas las bases de datos: $(tail -5 "$mysql_err_tmp")"
        rm -f "$dump_tmp" "$mysql_cnf_tmp" "$mysql_err_tmp"
        return 1
      fi
      log "Dump completo de la BD app falló; reintentando sin rutinas/eventos"
      if ! mysqldump "--defaults-extra-file=$mysql_cnf_tmp" --single-transaction --databases "$MYSQL_DB" > "$dump_tmp"; then
        rm -f "$dump_tmp" "$mysql_cnf_tmp" "$mysql_err_tmp"
        return 1
      fi
    fi
  fi
  rsync_exec "$RSYNC_FLAGS_BASE" "$dump_tmp" "$DEST:$MYSQL_DUMP_REMOTE_FILE"
  rsync_exec "$RSYNC_FLAGS_BASE" "$mysql_cnf_tmp" "$DEST:$MYSQL_CNF_REMOTE_FILE"
  rsync_exec "$RSYNC_FLAGS_BASE" "$mysql_user_sql_tmp" "$DEST:$MYSQL_USER_SQL_REMOTE_FILE"
  ssh_exec "set -eu; mysql --protocol=socket -uroot < '$MYSQL_USER_SQL_REMOTE_FILE'; chmod 600 '$MYSQL_CNF_REMOTE_FILE'; mysql --defaults-extra-file='$MYSQL_CNF_REMOTE_FILE' < '$MYSQL_DUMP_REMOTE_FILE'; rm -f '$MYSQL_DUMP_REMOTE_FILE' '$MYSQL_CNF_REMOTE_FILE' '$MYSQL_USER_SQL_REMOTE_FILE'"
  rm -f "$mysql_err_tmp" "$mysql_cnf_tmp" "$mysql_user_sql_tmp" "$dump_tmp"
  step_done 8
}

step9_permissions_services() {
  step_start 9 "Validar configuración y reiniciar servicios"
  ssh_exec 'set -eu
if command -v nginx >/dev/null 2>&1; then
  nginx_ssl_missing=0
  if grep -Rqs "/etc/letsencrypt" /etc/nginx/sites-enabled 2>/dev/null; then
    [ -r /etc/letsencrypt/options-ssl-nginx.conf ] || nginx_ssl_missing=1
  fi
  for cfg in /etc/nginx/sites-enabled/*; do
    [ -f "$cfg" ] || continue
    for ssl_file in $(sed -nE "s/^[[:space:]]*ssl_certificate(_key)?[[:space:]]+([^;[:space:]]+).*/\2/p" "$cfg"); do
      [ -r "$ssl_file" ] || nginx_ssl_missing=1
    done
  done
  if [ "$nginx_ssl_missing" -eq 1 ]; then
    echo "MIGRATION_WARNING|nginx|Faltan certificados Let’s Encrypt; se deshabilitan temporalmente los sitios SSL y se deja HTTP para poder emitir certificados en el destino"
    mkdir -p /etc/nginx/sites-disabled
    for cfg in /etc/nginx/sites-enabled/*; do
      [ -f "$cfg" ] || continue
      if grep -Eq "/etc/letsencrypt|listen[[:space:]].*443[[:space:]].*ssl" "$cfg"; then
        disabled="/etc/nginx/sites-disabled/$(basename "$cfg").migration-disabled"
        rm -f "$disabled"
        mv "$cfg" "$disabled"
        echo "MIGRATION_WARNING|nginx|Sitio deshabilitado temporalmente: $(basename "$cfg")"
      fi
    done
    php_sock="$(find /run/php -maxdepth 1 -type s -name "php*-fpm.sock" -print -quit 2>/dev/null || true)"
    php_pass="127.0.0.1:9000"
    [ -z "$php_sock" ] || php_pass="unix:$php_sock"
    cat > /etc/nginx/conf.d/palweb-migration-http.conf <<EOF
server {
    listen 80 default_server;
    listen [::]:80 default_server;
    server_name _;
    root /var/www;
    index index.php index.html;
    client_max_body_size 512M;
    location ^~ /.well-known/acme-challenge/ {
        allow all;
        try_files \$uri =404;
    }
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
  nginx -t
fi
systemctl daemon-reload
for svc in nginx apache2 php8.1-fpm php8.2-fpm php8.3-fpm php8.4-fpm mariadb mysql; do
  if systemctl is-active --quiet "$svc" || systemctl is-enabled --quiet "$svc" 2>/dev/null; then
    systemctl restart "$svc"
  fi
done
systemctl --failed --no-pager || true'
  if [[ "$SETUP_SSL" == "yes" ]]; then
    rsync_exec "$RSYNC_FLAGS_BASE" "$POST_INSTALL_SSL_SCRIPT" "$DEST:$POST_INSTALL_SSL_REMOTE_FILE"
    ssh_exec "set -eu; chmod 700 '$POST_INSTALL_SSL_REMOTE_FILE'; MIRROR_SSL_DOMAIN='$SSL_DOMAIN' MIRROR_SSL_EMAIL='$SSL_EMAIL' bash '$POST_INSTALL_SSL_REMOTE_FILE'; rm -f '$POST_INSTALL_SSL_REMOTE_FILE'"
  fi
  step_done 9
}

step10_validate() {
  step_start 10 "Smoke test final"
  ssh_exec "set -eu
test -f /var/www/db.php
for f in /var/www/pos.php /var/www/pos_save.php /var/www/kardex_engine.php /var/www/config_loader.php; do
  [ ! -f \"\$f\" ] || php -l \"\$f\" >/dev/null
done
if command -v nginx >/dev/null 2>&1; then nginx -t; fi
php -r 'require \"/var/www/db.php\"; if (!isset(\$pdo)) exit(1); echo \"DB_OK\\n\";'
if [ \"$SYNC_IMAGES\" = yes ]; then test -d '$PRODUCT_IMAGES_PATH'; fi
echo MIGRATION_SMOKE_TEST_OK"
  step_done 10
}

run_one_step() {
  case "$1" in
    1) step1_bootstrap_remote ;;
    2) step2_prepare_remote ;;
    3) step3_sync_www ;;
    4) step4_install_app_dependencies ;;
    5) step5_sync_configs ;;
    6) step6_sync_systemd_cron ;;
    7) step7_sync_users_groups ;;
    8) step8_mysql_dump_restore ;;
    9) step9_permissions_services ;;
    10) step10_validate ;;
    *) err "Paso inválido: $1"; exit 1 ;;
  esac
}

main() {
  log "Iniciando migración PalWeb POS"
  log "Destino: $DEST | puerto: $SSH_PORT | modo: $MODE | destino: $TARGET_MODE | BD: $DB_SCOPE"
  log "Configuraciones: $SYNC_CONFIGS | imágenes: $SYNC_IMAGES | usuarios: $SYNC_USERS | delete: $ALLOW_DELETE"
  if [[ "$SETUP_SSL" == "yes" ]]; then
    log "Post-instalación SSL habilitada para: $SSL_DOMAIN"
  fi
  if [[ "$TARGET_MODE" == "new" ]]; then
    log "Transferencia inicial: tar+zstd; las actualizaciones deben usar target-mode update con rsync"
  else
    log "Transferencia de actualización: rsync incremental"
  fi
  ensure_local_requirements
  start_ssh_master
  if [[ -n "$ONLY_STEP" && "$ONLY_STEP" != all ]]; then
    run_one_step "$ONLY_STEP"
  else
    for n in 1 2 3 4 5 6 7 8 9 10; do run_one_step "$n"; done
  fi
  log "Proceso finalizado ($MODE)"
  if [[ "$MODE" == "dry-run" ]]; then
    log "No se aplicaron cambios; repite con --mode run para ejecutar la migración real."
  fi
  return 0
}

main "$@"
