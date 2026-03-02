#!/bin/bash
# Script de sincronización: /var/www/marinero -> /var/www/
# Sincroniza todos los archivos PHP, JS, CSS y recursos modificados

echo "🔄 Sincronizando archivos de marinero a raíz..."

# Excluir directorios que no deben sincronizarse (vendor, node_modules, etc.)
rsync -av --delete \
    --exclude='vendor/' \
    --exclude='node_modules/' \
    --exclude='.git/' \
    --exclude='.claude/' \
    --exclude='venv/' \
    --exclude='__pycache__/' \
    --exclude='.aider.*' \
    --exclude='sync_to_www.sh' \
    --exclude='backup_*.sql' \
    /var/www/marinero/ /var/www/

echo "✅ Sincronización completada"
echo "📅 $(date '+%Y-%m-%d %H:%M:%S')"
