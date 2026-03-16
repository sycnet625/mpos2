<?php
declare(strict_types=1);

if ($argc < 5) {
    fwrite(STDERR, "Uso: php publish_update_json.php <version> <build> <zip_url> <salida_json> [notas]\n");
    exit(1);
}

$version = $argv[1];
$build = $argv[2];
$zipUrl = $argv[3];
$output = $argv[4];
$notes = $argv[5] ?? 'Build Tauri publicada manualmente.';

$payload = [
    'product' => 'PalWeb SNMP VU Tauri',
    'version' => $version,
    'build' => $build,
    'zip_url' => $zipUrl,
    'notes' => $notes,
    'kind' => 'portable_zip',
];

$json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
if ($json === false) {
    fwrite(STDERR, "No se pudo codificar JSON\n");
    exit(1);
}

if (file_put_contents($output, $json . PHP_EOL) === false) {
    fwrite(STDERR, "No se pudo escribir: {$output}\n");
    exit(1);
}

echo "OK {$output}\n";
