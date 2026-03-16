<?php
declare(strict_types=1);

if ($argc < 2) {
    fwrite(STDERR, "Uso: php bump_version.php <patch|minor|major|X.Y.Z>\n");
    exit(1);
}

$root = dirname(__DIR__);
$mode = trim($argv[1]);

$cargoFile = $root . '/src-tauri/Cargo.toml';
$packageFile = $root . '/package.json';
$tauriFile = $root . '/src-tauri/tauri.conf.json';

$cargo = file_get_contents($cargoFile);
$package = json_decode((string) file_get_contents($packageFile), true, 512, JSON_THROW_ON_ERROR);
$tauri = json_decode((string) file_get_contents($tauriFile), true, 512, JSON_THROW_ON_ERROR);

if (!preg_match('/^version\s*=\s*"(\d+)\.(\d+)\.(\d+)"/m', (string) $cargo, $m)) {
    fwrite(STDERR, "No se pudo leer version desde Cargo.toml\n");
    exit(1);
}

$major = (int) $m[1];
$minor = (int) $m[2];
$patch = (int) $m[3];

if (preg_match('/^\d+\.\d+\.\d+$/', $mode)) {
    [$major, $minor, $patch] = array_map('intval', explode('.', $mode));
} else {
    switch ($mode) {
        case 'patch':
            $patch++;
            break;
        case 'minor':
            $minor++;
            $patch = 0;
            break;
        case 'major':
            $major++;
            $minor = 0;
            $patch = 0;
            break;
        default:
            fwrite(STDERR, "Modo invalido: {$mode}\n");
            exit(1);
    }
}

$newVersion = sprintf('%d.%d.%d', $major, $minor, $patch);

$cargo = preg_replace('/^(version\s*=\s*")(\d+\.\d+\.\d+)(")$/m', '${1}' . $newVersion . '${3}', (string) $cargo, 1);
if ($cargo === null) {
    fwrite(STDERR, "No se pudo actualizar Cargo.toml\n");
    exit(1);
}

$package['version'] = $newVersion;
$tauri['version'] = $newVersion;

file_put_contents($cargoFile, $cargo);
file_put_contents($packageFile, json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
file_put_contents($tauriFile, json_encode($tauri, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);

echo $newVersion . PHP_EOL;
