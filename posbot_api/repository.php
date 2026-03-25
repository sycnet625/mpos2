<?php
function bot_repo_file(string $key): string {
    return (string)bot_context_get($key, '');
}

function bot_repo_read(string $key, array $default = []): array {
    $file = bot_repo_file($key);
    if ($file === '') return $default;
    return bot_read_json_file($file, $default);
}

function bot_repo_write(string $key, array $data): bool {
    $file = bot_repo_file($key);
    if ($file === '') return false;
    return bot_write_json_file($file, $data);
}

function bot_bridge_status_file(): string {
    return bot_repo_file('bridge_status_file');
}

function bot_bridge_runtime_dir(): string {
    return bot_repo_file('bridge_runtime_dir');
}
