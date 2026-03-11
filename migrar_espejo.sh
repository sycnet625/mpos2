#!/usr/bin/env bash
set -euo pipefail

# Migracion espejo de /var/www + configs + cron + systemd + MySQL + usuarios/grupos
# Uso:
#   ./migrar_espejo.sh --dest root@IP_DESTINO [--ssh-port 22] [--mode dry-run|run] [--sync-users yes|no] [--ssh-password xxx] [--only-step N]

DEST=""
SSH_PORT="22"
MODE="dry-run"
MYSQL_DUMP_REMOTE_FILE="/tmp/all_databases_mirror.sql"
MYSQL_CNF_REMOTE_FILE="/tmp/mirror_mysql.cnf"
DB_PHP_PATH="/var/www/db.php"
RSYNC_FLAGS_BASE="-aHAX --numeric-ids --info=progress2"
RSYNC_FLAGS_WWW="$RSYNC_FLAGS_BASE -x --delete"
SYNC_USERS="yes"
SSH_PASSWORD=""
ONLY_STEP=""

CONTROL_PATH="/tmp/mirror_ssh_mux_${RANDOM}_%r@%h:%p"
MIRROR_HOME="/tmp/mirror_home"
KNOWN_HOSTS_FILE="/dev/null"
SSH_OPTS=(
  -p "$SSH_PORT"
  -o StrictHostKeyChecking=no
  -o UserKnownHostsFile="$KNOWN_HOSTS_FILE"
  -o GlobalKnownHostsFile="$KNOWN_HOSTS_FILE"
  -F /dev/null
  -o ControlMaster=auto
  -o ControlPath="$CONTROL_PATH"
  -o ControlPersist=30m
)

log() { printf '[%s] %s\n' "$(date '+%F %T')" "$*"; }
err() { printf '[%s] ERROR: %s\n' "$(date '+%F %T')" "$*" >&2; }
step_start() { printf 'STEP_START:%s:%s\n' "$1" "$2"; }
step_done() { printf 'STEP_DONE:%s\n' "$1"; }

usage() {
  cat <<USAGE
Uso:
  $0 --dest root@IP_DESTINO [--ssh-port 22] [--mode dry-run|run] [--sync-users yes|no] [--ssh-password xxx] [--only-step N]

Parametros:
  --dest         Destino SSH (ej: root@203.0.113.10)
  --ssh-port     Puerto SSH (default: 22)
  --mode         dry-run (default) o run
  --sync-users   yes (default) | no
  --ssh-password Clave SSH remota (opcional). Si no se indica, usa prompt/interactivo o llave SSH.
  --only-step    Ejecuta solo un paso (1..7)

Pasos:
  1 Preparar conexión remota
  2 Sincronizar /var/www
  3 Sincronizar configs (/etc/nginx, php, apache2, mysql, letsencrypt)
  4 Sincronizar systemd y cron
  5 Sincronizar usuarios/grupos (UID/GID >= 1000)
  6 Dump+restore MySQL
  7 Permisos y reinicio de servicios
USAGE
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --dest)
      DEST="${2:-}"
      shift 2
      ;;
    --ssh-port)
      SSH_PORT="${2:-}"
      shift 2
      ;;
    --mode)
      MODE="${2:-}"
      shift 2
      ;;
    --sync-users)
      SYNC_USERS="${2:-}"
      shift 2
      ;;
    --ssh-password)
      SSH_PASSWORD="${2:-}"
      shift 2
      ;;
    --only-step)
      ONLY_STEP="${2:-}"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      err "Parametro no reconocido: $1"
      usage
      exit 1
      ;;
  esac
done

if [[ -z "$DEST" ]]; then
  err "Debes indicar --dest"
  usage
  exit 1
fi
if [[ "$MODE" != "dry-run" && "$MODE" != "run" ]]; then
  err "--mode debe ser dry-run o run"
  exit 1
fi
if [[ "$SYNC_USERS" != "yes" && "$SYNC_USERS" != "no" ]]; then
  err "--sync-users debe ser yes o no"
  exit 1
fi
if [[ -n "$ONLY_STEP" && ! "$ONLY_STEP" =~ ^[1-7]$ ]]; then
  err "--only-step debe estar entre 1 y 7"
  exit 1
fi

SSH_OPTS=(
  -p "$SSH_PORT"
  -o StrictHostKeyChecking=no
  -o UserKnownHostsFile="$KNOWN_HOSTS_FILE"
  -o GlobalKnownHostsFile="$KNOWN_HOSTS_FILE"
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
  local flags="$1"
  local src="$2"
  local dst="$3"

  local rsh="ssh -p $SSH_PORT -o StrictHostKeyChecking=no -o UserKnownHostsFile=$KNOWN_HOSTS_FILE -o GlobalKnownHostsFile=$KNOWN_HOSTS_FILE -F /dev/null -o ControlMaster=auto -o ControlPath=$CONTROL_PATH -o ControlPersist=30m"
  if [[ -n "$SSH_PASSWORD" ]]; then
    rsh="sshpass -e $rsh"
  fi

  if [[ "$MODE" == "dry-run" ]]; then
    log "rsync --dry-run $flags -e '$rsh' '$src' '$dst'"
    if [[ -n "$SSH_PASSWORD" ]]; then
      SSHPASS="$SSH_PASSWORD" rsync --dry-run $flags -e "$rsh" "$src" "$dst"
    else
      rsync --dry-run $flags -e "$rsh" "$src" "$dst"
    fi
  else
    log "rsync $flags -e '$rsh' '$src' '$dst'"
    if [[ -n "$SSH_PASSWORD" ]]; then
      SSHPASS="$SSH_PASSWORD" rsync $flags -e "$rsh" "$src" "$dst"
    else
      rsync $flags -e "$rsh" "$src" "$dst"
    fi
  fi
}

ensure_local_requirements() {
  for bin in rsync ssh mysqldump mysql getent awk php; do
    command -v "$bin" >/dev/null 2>&1 || { err "Falta comando local: $bin"; exit 1; }
  done
  if [[ -n "$SSH_PASSWORD" ]]; then
    command -v sshpass >/dev/null 2>&1 || { err "Falta sshpass para --ssh-password"; exit 1; }
  fi
  mkdir -p "$MIRROR_HOME" 2>/dev/null || true
  export HOME="$MIRROR_HOME"
}

load_mysql_from_dbphp() {
  if [[ ! -f "$DB_PHP_PATH" ]]; then
    err "No existe $DB_PHP_PATH para leer credenciales MySQL"
    return 1
  fi

  local parsed
  parsed="$(php -r '
    $f = "'"$DB_PHP_PATH"'";
    $s = @file_get_contents($f);
    if ($s === false) { exit(2); }
    foreach (["host","db","user","pass","port"] as $k) {
      if (preg_match("/\\$".$k."\\s*=\\s*'\''([^'\'']*)'\''\\s*;/", $s, $m)) {
        echo $k."=".$m[1].PHP_EOL;
      }
    }
  ')"

  MYSQL_HOST="$(printf '%s\n' "$parsed" | awk -F= '$1=="host"{print $2}')"
  MYSQL_DB="$(printf '%s\n' "$parsed" | awk -F= '$1=="db"{print $2}')"
  MYSQL_USER="$(printf '%s\n' "$parsed" | awk -F= '$1=="user"{print $2}')"
  MYSQL_PASS="$(printf '%s\n' "$parsed" | awk -F= '$1=="pass"{print $2}')"
  MYSQL_PORT="$(printf '%s\n' "$parsed" | awk -F= '$1=="port"{print $2}')"

  [[ -z "${MYSQL_HOST:-}" ]] && MYSQL_HOST="127.0.0.1"
  [[ -z "${MYSQL_PORT:-}" ]] && MYSQL_PORT="3306"
  if [[ -z "${MYSQL_DB:-}" || -z "${MYSQL_USER:-}" ]]; then
    err "No se pudieron extraer credenciales válidas de $DB_PHP_PATH"
    return 1
  fi
}

start_ssh_master() {
  if [[ "$MODE" == "dry-run" ]]; then
    log "[DRY-RUN] Se omite apertura de túnel SSH persistente"
    return 0
  fi

  log "Abriendo conexión SSH persistente (si pide clave, será una sola vez)"
  if [[ -n "$SSH_PASSWORD" ]]; then
    SSHPASS="$SSH_PASSWORD" sshpass -e ssh "${SSH_OPTS[@]}" "$DEST" "echo ok" >/dev/null
  else
    ssh "${SSH_OPTS[@]}" "$DEST" "echo ok" >/dev/null
  fi
}

cleanup_ssh_master() {
  if [[ "$MODE" == "run" ]]; then
    if [[ -n "$SSH_PASSWORD" ]]; then
      SSHPASS="$SSH_PASSWORD" sshpass -e ssh -O exit "${SSH_OPTS[@]}" "$DEST" >/dev/null 2>&1 || true
    else
      ssh -O exit "${SSH_OPTS[@]}" "$DEST" >/dev/null 2>&1 || true
    fi
  fi
}
trap cleanup_ssh_master EXIT

step1_prepare_remote() {
  step_start 1 "Preparar conexión remota"
  ssh_exec "mkdir -p /var/www /root"
  step_done 1
}

step2_sync_www() {
  step_start 2 "Sincronizar /var/www"
  rsync_exec "$RSYNC_FLAGS_WWW" "/var/www/" "$DEST:/var/www/"
  step_done 2
}

step3_sync_configs() {
  step_start 3 "Sincronizar configuraciones"
  [[ -d /etc/nginx ]] && rsync_exec "$RSYNC_FLAGS_BASE" "/etc/nginx/" "$DEST:/etc/nginx/" || log "Saltando /etc/nginx"
  [[ -d /etc/php ]] && rsync_exec "$RSYNC_FLAGS_BASE" "/etc/php/" "$DEST:/etc/php/" || log "Saltando /etc/php"
  [[ -d /etc/apache2 ]] && rsync_exec "$RSYNC_FLAGS_BASE" "/etc/apache2/" "$DEST:/etc/apache2/" || log "Saltando /etc/apache2"
  [[ -d /etc/mysql ]] && rsync_exec "$RSYNC_FLAGS_BASE" "/etc/mysql/" "$DEST:/etc/mysql/" || log "Saltando /etc/mysql"
  [[ -d /etc/letsencrypt ]] && rsync_exec "$RSYNC_FLAGS_BASE" "/etc/letsencrypt/" "$DEST:/etc/letsencrypt/" || log "Saltando /etc/letsencrypt"
  step_done 3
}

step4_sync_systemd_cron() {
  step_start 4 "Sincronizar systemd y cron"
  [[ -d /etc/systemd/system ]] && rsync_exec "$RSYNC_FLAGS_BASE" "/etc/systemd/system/" "$DEST:/etc/systemd/system/" || log "Saltando /etc/systemd/system"
  [[ -d /etc/cron.d ]] && rsync_exec "$RSYNC_FLAGS_BASE" "/etc/cron.d/" "$DEST:/etc/cron.d/" || log "Saltando /etc/cron.d"

  local cron_tmp
  cron_tmp="$(mktemp /tmp/cron.root.XXXXXX)"

  if crontab -l >"$cron_tmp" 2>/dev/null; then
    rsync_exec "$RSYNC_FLAGS_BASE" "$cron_tmp" "$DEST:/root/cron.root.txt"
    ssh_exec "crontab /root/cron.root.txt"
  else
    log "No hay crontab de root local"
  fi

  ssh_exec "systemctl daemon-reload || true"
  rm -f "$cron_tmp" || true
  step_done 4
}

step5_sync_users_groups() {
  step_start 5 "Sincronizar usuarios y grupos"
  if [[ "$SYNC_USERS" != "yes" ]]; then
    log "Saltando usuarios/grupos (--sync-users no)"
    step_done 5
    return 0
  fi

  local groups_tmp users_tmp
  groups_tmp="$(mktemp /tmp/mirror_groups.XXXXXX)"
  users_tmp="$(mktemp /tmp/mirror_users.XXXXXX)"

  getent group | awk -F: '$3>=1000 && $1!="nogroup"{print}' > "$groups_tmp"
  getent passwd | awk -F: '$3>=1000 && $1!="nobody"{print}' > "$users_tmp"

  rsync_exec "$RSYNC_FLAGS_BASE" "$groups_tmp" "$DEST:/root/mirror_groups.txt"
  rsync_exec "$RSYNC_FLAGS_BASE" "$users_tmp" "$DEST:/root/mirror_users.txt"

  ssh_exec "while IFS=: read -r name _ gid _; do getent group \"\$name\" >/dev/null || groupadd -g \"\$gid\" \"\$name\"; done < /root/mirror_groups.txt"
  ssh_exec "while IFS=: read -r name _ uid gid gecos home shell; do id -u \"\$name\" >/dev/null 2>&1 || useradd -u \"\$uid\" -g \"\$gid\" -d \"\$home\" -s \"\$shell\" -c \"\$gecos\" -M \"\$name\"; done < /root/mirror_users.txt"
  rm -f "$groups_tmp" "$users_tmp" || true
  step_done 5
}

step6_mysql_dump_restore() {
  step_start 6 "Dump y restore de MySQL"
  local dump_tmp mysql_cnf_tmp mysql_err_tmp
  dump_tmp="$(mktemp /tmp/all_databases_mirror.XXXXXX.sql)"
  mysql_cnf_tmp="$(mktemp /tmp/mirror_mysql.XXXXXX.cnf)"
  mysql_err_tmp="$(mktemp /tmp/mirror_mysqldump.XXXXXX.log)"

  load_mysql_from_dbphp

  cat > "$mysql_cnf_tmp" <<EOF
[client]
host=${MYSQL_HOST}
port=${MYSQL_PORT}
user=${MYSQL_USER}
password=${MYSQL_PASS}
EOF
  chmod 600 "$mysql_cnf_tmp" || true

  if [[ "$MODE" == "dry-run" ]]; then
    log "[DRY-RUN] mysqldump --defaults-extra-file=$mysql_cnf_tmp --all-databases --routines --triggers --events --single-transaction > $dump_tmp"
  else
    if ! mysqldump --defaults-extra-file="$mysql_cnf_tmp" --all-databases --routines --triggers --events --single-transaction > "$dump_tmp" 2>"$mysql_err_tmp"; then
      log "Dump full falló, intento fallback solo DB app: ${MYSQL_DB}"
      mysqldump --defaults-extra-file="$mysql_cnf_tmp" --routines --triggers --events --single-transaction --databases "$MYSQL_DB" > "$dump_tmp"
    fi
  fi

  rsync_exec "$RSYNC_FLAGS_BASE" "$dump_tmp" "$DEST:$MYSQL_DUMP_REMOTE_FILE"
  rsync_exec "$RSYNC_FLAGS_BASE" "$mysql_cnf_tmp" "$DEST:$MYSQL_CNF_REMOTE_FILE"
  ssh_exec "chmod 600 $MYSQL_CNF_REMOTE_FILE || true"
  ssh_exec "mysql --defaults-extra-file=$MYSQL_CNF_REMOTE_FILE < $MYSQL_DUMP_REMOTE_FILE"
  ssh_exec "rm -f $MYSQL_DUMP_REMOTE_FILE $MYSQL_CNF_REMOTE_FILE || true"
  rm -f "$mysql_err_tmp" || true
  rm -f "$mysql_cnf_tmp" || true
  rm -f "$dump_tmp" || true
  step_done 6
}

step7_permissions_services() {
  step_start 7 "Permisos y servicios"
  ssh_exec "chown -R www-data:www-data /var/www || true"
  ssh_exec "systemctl restart nginx 2>/dev/null || true"
  ssh_exec "systemctl restart apache2 2>/dev/null || true"
  ssh_exec "systemctl restart php8.1-fpm 2>/dev/null || true"
  ssh_exec "systemctl restart php8.2-fpm 2>/dev/null || true"
  ssh_exec "systemctl restart php8.3-fpm 2>/dev/null || true"
  ssh_exec "systemctl restart mariadb 2>/dev/null || systemctl restart mysql 2>/dev/null || true"
  ssh_exec "nginx -t 2>/dev/null || true"
  ssh_exec "systemctl --failed --no-pager || true"
  step_done 7
}

run_one_step() {
  case "$1" in
    1) step1_prepare_remote ;;
    2) step2_sync_www ;;
    3) step3_sync_configs ;;
    4) step4_sync_systemd_cron ;;
    5) step5_sync_users_groups ;;
    6) step6_mysql_dump_restore ;;
    7) step7_permissions_services ;;
    *) err "Paso inválido: $1"; exit 1 ;;
  esac
}

main() {
  log "Iniciando migración espejo"
  log "Destino: $DEST"
  log "Puerto SSH: $SSH_PORT"
  log "Modo: $MODE"
  log "Sync usuarios/grupos: $SYNC_USERS"
  ensure_local_requirements
  start_ssh_master

  if [[ -n "$ONLY_STEP" ]]; then
    run_one_step "$ONLY_STEP"
  else
    run_one_step 1
    run_one_step 2
    run_one_step 3
    run_one_step 4
    run_one_step 5
    run_one_step 6
    run_one_step 7
  fi

  log "Proceso finalizado ($MODE)"
  if [[ "$MODE" == "dry-run" ]]; then
    log "No se aplicaron cambios. Repite con --mode run para ejecutar migración real."
  fi
}

main "$@"
