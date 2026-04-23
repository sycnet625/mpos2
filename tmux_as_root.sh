#!/bin/bash
# Wrapper para ejecutar tmux como root sin password
# Setuid: chmod 4755 /var/www/tmux_as_root.sh

exec /usr/bin/tmux -S /var/www/tmux_ai_socket "$@"