#!/bin/bash
# Script de sincronización segura: /var/www/marinero -> /var/www/
# Evita borrar la propia carpeta fuente cuando se usa --delete.

set -euo pipefail

SRC="/var/www/marinero/"
DEST="/var/www/"
MODE="${1:-}"

echo "🔄 Sincronizando archivos de marinero a raíz..."

RSYNC_ARGS=(
    -av
    --delete
    --exclude='vendor/'
    --exclude='node_modules/'
    --exclude='.git/'
    --exclude='.claude/'
    --exclude='venv/'
    --exclude='__pycache__/'
    --exclude='.aider.*'
    --exclude='sync_to_www.sh'
    --exclude='backup_*.sql'
    --exclude='marinero/'                # Protege /var/www/marinero en el destino
    --filter='P /marinero/***'           # Protección fuerte contra borrado con --delete
)

if [[ "$MODE" == "--dry-run" ]]; then
    RSYNC_ARGS+=(--dry-run --itemize-changes)
    echo "🧪 Modo prueba (sin cambios reales)"
fi

rsync "${RSYNC_ARGS[@]}" "$SRC" "$DEST"

echo "✅ Sincronización completada"
echo "📅 $(date '+%Y-%m-%d %H:%M:%S')"
