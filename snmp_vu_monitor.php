<?php
declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
header('Content-Type: text/html; charset=utf-8');

const SNMP_VU_STORAGE_DIR = __DIR__ . '/storage';
const SNMP_VU_CONFIG_FILE = SNMP_VU_STORAGE_DIR . '/snmp_vu_monitor_config.json';
const SNMP_VU_STATE_FILE = SNMP_VU_STORAGE_DIR . '/snmp_vu_monitor_state.json';

function snmp_vu_ensure_storage(): void {
    if (!is_dir(SNMP_VU_STORAGE_DIR)) {
        @mkdir(SNMP_VU_STORAGE_DIR, 0775, true);
    }
}

function snmp_vu_defaults(): array {
    $items = [];
    for ($i = 0; $i < 5; $i++) {
        $items[] = [
            'enabled' => $i === 0 ? 1 : 0,
            'label' => 'VU ' . ($i + 1),
            'host' => '192.168.88.1',
            'community' => 'public',
            'version' => '2c',
            'oid' => '',
            'walk_oid' => '1.3.6.1.2.1.2.2.1',
            'mode' => 'counter_bytes',
            'scale_mbps' => 100,
            'ping_ip' => '192.168.88.1',
        ];
    }

    return [
        'refresh_ms' => 3000,
        'items' => $items,
    ];
}

function snmp_vu_load_config(): array {
    snmp_vu_ensure_storage();
    $defaults = snmp_vu_defaults();
    if (!is_file(SNMP_VU_CONFIG_FILE)) {
        @file_put_contents(SNMP_VU_CONFIG_FILE, json_encode($defaults, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $defaults;
    }
    $raw = @file_get_contents(SNMP_VU_CONFIG_FILE);
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return $defaults;
    }
    $data['refresh_ms'] = max(1000, min(30000, (int)($data['refresh_ms'] ?? 3000)));
    $items = $data['items'] ?? [];
    for ($i = 0; $i < 5; $i++) {
        $base = $defaults['items'][$i];
        $row = is_array($items[$i] ?? null) ? $items[$i] : [];
        $defaults['items'][$i] = array_merge($base, $row);
    }
    $defaults['refresh_ms'] = $data['refresh_ms'];
    return $defaults;
}

function snmp_vu_save_config(array $config): bool {
    snmp_vu_ensure_storage();
    return @file_put_contents(SNMP_VU_CONFIG_FILE, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) !== false;
}

function snmp_vu_load_state(): array {
    $raw = @file_get_contents(SNMP_VU_STATE_FILE);
    $data = json_decode((string)$raw, true);
    return is_array($data) ? $data : [];
}

function snmp_vu_save_state(array $state): void {
    snmp_vu_ensure_storage();
    @file_put_contents(SNMP_VU_STATE_FILE, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}

function snmp_vu_json(array $data): void {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function snmp_vu_clean_item(array $src, int $idx): array {
    return [
        'enabled' => !empty($src['enabled']) ? 1 : 0,
        'label' => trim((string)($src['label'] ?? ('VU ' . ($idx + 1)))) ?: ('VU ' . ($idx + 1)),
        'host' => trim((string)($src['host'] ?? '')),
        'community' => trim((string)($src['community'] ?? 'public')),
        'version' => in_array((string)($src['version'] ?? '2c'), ['1', '2c'], true) ? (string)$src['version'] : '2c',
        'oid' => trim((string)($src['oid'] ?? '')),
        'walk_oid' => trim((string)($src['walk_oid'] ?? '1.3.6.1.2.1.2.2.1')),
        'mode' => in_array((string)($src['mode'] ?? 'counter_bytes'), ['counter_bytes', 'counter_bits', 'direct_mbps', 'direct_bps'], true) ? (string)$src['mode'] : 'counter_bytes',
        'scale_mbps' => max(1, (float)($src['scale_mbps'] ?? 100)),
        'ping_ip' => trim((string)($src['ping_ip'] ?? '')),
    ];
}

function snmp_vu_snmp_available(): array {
    $php = function_exists('snmp2_get');
    $getBin = trim((string)@shell_exec('command -v snmpget 2>/dev/null'));
    $walkBin = trim((string)@shell_exec('command -v snmpwalk 2>/dev/null'));
    return [
        'php' => $php,
        'get_bin' => $getBin,
        'walk_bin' => $walkBin,
        'ok' => $php || ($getBin !== '' && $walkBin !== ''),
    ];
}

function snmp_vu_parse_numeric(string $value): ?float {
    $clean = trim($value);
    if ($clean === '') return null;
    if (preg_match('/-?\d+(?:\.\d+)?/', $clean, $m)) {
        return (float)$m[0];
    }
    return null;
}

function snmp_vu_get_raw(string $host, string $community, string $version, string $oid): array {
    $caps = snmp_vu_snmp_available();
    if ($host === '' || $community === '' || $oid === '') {
        return ['ok' => false, 'msg' => 'Faltan host, community u OID'];
    }

    if (function_exists('snmp2_get')) {
        @snmp_set_quick_print(true);
        @snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        $res = @snmp2_get($host, $community, $oid, 1000000, 1);
        if ($res !== false) {
            $raw = is_array($res) ? (string)reset($res) : (string)$res;
            return ['ok' => true, 'raw' => $raw];
        }
    }

    $bin = trim((string)@shell_exec('command -v snmpget 2>/dev/null'));
    if ($bin !== '') {
        $cmd = escapeshellcmd($bin) . ' -v ' . escapeshellarg($version) . ' -c ' . escapeshellarg($community) .
            ' -Oqv ' . escapeshellarg($host) . ' ' . escapeshellarg($oid) . ' 2>&1';
        $out = @shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') {
            return ['ok' => true, 'raw' => trim($out)];
        }
    }

    return ['ok' => false, 'msg' => 'SNMP no disponible. Instale php-snmp o snmp/snmpwalk en Ubuntu.'];
}

function snmp_vu_walk(string $host, string $community, string $version, string $oid): array {
    if ($host === '' || $community === '' || $oid === '') {
        return ['ok' => false, 'msg' => 'Faltan host, community u OID base'];
    }

    if (function_exists('snmp2_real_walk')) {
        @snmp_set_quick_print(true);
        @snmp_set_valueretrieval(SNMP_VALUE_PLAIN);
        $rows = @snmp2_real_walk($host, $community, $oid, 1000000, 1);
        if (is_array($rows)) {
            $lines = [];
            foreach ($rows as $key => $value) {
                $lines[] = $key . ' = ' . $value;
            }
            return ['ok' => true, 'output' => implode("\n", $lines)];
        }
    }

    $bin = trim((string)@shell_exec('command -v snmpwalk 2>/dev/null'));
    if ($bin !== '') {
        $cmd = escapeshellcmd($bin) . ' -v ' . escapeshellarg($version) . ' -c ' . escapeshellarg($community) .
            ' -On ' . escapeshellarg($host) . ' ' . escapeshellarg($oid) . ' 2>&1';
        $out = @shell_exec($cmd);
        if (is_string($out) && trim($out) !== '') {
            return ['ok' => true, 'output' => trim($out)];
        }
    }

    return ['ok' => false, 'msg' => 'SNMP walk no disponible. Instale php-snmp o snmp/snmpwalk.'];
}

function snmp_vu_ping(string $ip): bool {
    if ($ip === '') return false;
    $cmd = '/usr/bin/ping -c 1 -W 1 ' . escapeshellarg($ip) . ' >/dev/null 2>&1; echo $?';
    return trim((string)@shell_exec($cmd)) === '0';
}

function snmp_vu_calculate_mbps(float $raw, string $mode, array $prev, int $now): float {
    if ($mode === 'direct_mbps') {
        return max(0, $raw);
    }
    if ($mode === 'direct_bps') {
        return max(0, $raw / 1000000);
    }

    $lastRaw = isset($prev['raw']) ? (float)$prev['raw'] : null;
    $lastTs = isset($prev['ts']) ? (int)$prev['ts'] : null;
    if ($lastRaw === null || $lastTs === null || $now <= $lastTs || $raw < $lastRaw) {
        return 0.0;
    }
    $delta = $raw - $lastRaw;
    $seconds = max(1, $now - $lastTs);
    if ($mode === 'counter_bits') {
        return max(0, ($delta / $seconds) / 1000000);
    }
    return max(0, (($delta / $seconds) * 8) / 1000000);
}

$config = snmp_vu_load_config();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_config') {
    $items = [];
    for ($i = 0; $i < 5; $i++) {
        $items[] = snmp_vu_clean_item($_POST['items'][$i] ?? [], $i);
    }
    $newConfig = [
        'refresh_ms' => max(1000, min(30000, (int)($_POST['refresh_ms'] ?? 3000))),
        'items' => $items,
    ];
    if (!snmp_vu_save_config($newConfig)) {
        snmp_vu_json(['status' => 'error', 'msg' => 'No se pudo guardar la configuración en disco']);
    }
    snmp_vu_json(['status' => 'success', 'msg' => 'Configuración guardada', 'config' => $newConfig]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'walk') {
    $item = snmp_vu_clean_item($_POST, 0);
    $walk = snmp_vu_walk($item['host'], $item['community'], $item['version'], $item['walk_oid']);
    snmp_vu_json($walk['ok'] ? ['status' => 'success', 'output' => $walk['output']] : ['status' => 'error', 'msg' => $walk['msg']]);
}

if (isset($_GET['action']) && $_GET['action'] === 'poll') {
    $state = snmp_vu_load_state();
    $now = time();
    $rows = [];
    foreach ($config['items'] as $idx => $item) {
        $enabled = !empty($item['enabled']);
        $row = [
            'index' => $idx,
            'label' => $item['label'],
            'enabled' => $enabled,
            'mbps' => 0,
            'percent' => 0,
            'raw' => null,
            'ok' => false,
            'ping_ok' => snmp_vu_ping($item['ping_ip']),
            'msg' => $enabled ? 'Sin datos' : 'Desactivado',
            'scale_mbps' => (float)$item['scale_mbps'],
        ];
        if ($enabled) {
            $snmp = snmp_vu_get_raw($item['host'], $item['community'], $item['version'], $item['oid']);
            if ($snmp['ok']) {
                $raw = snmp_vu_parse_numeric((string)$snmp['raw']);
                if ($raw !== null) {
                    $prev = $state[(string)$idx] ?? [];
                    $mbps = snmp_vu_calculate_mbps($raw, $item['mode'], is_array($prev) ? $prev : [], $now);
                    $row['ok'] = true;
                    $row['raw'] = $raw;
                    $row['mbps'] = round($mbps, 2);
                    $row['percent'] = max(0, min(100, (($mbps / max(1, (float)$item['scale_mbps'])) * 100)));
                    $row['msg'] = 'OK';
                    $state[(string)$idx] = ['raw' => $raw, 'ts' => $now];
                } else {
                    $row['msg'] = 'Respuesta SNMP no numérica';
                }
            } else {
                $row['msg'] = $snmp['msg'];
            }
        }
        $rows[] = $row;
    }
    snmp_vu_save_state($state);
    snmp_vu_json([
        'status' => 'success',
        'items' => $rows,
        'capabilities' => snmp_vu_snmp_available(),
        'storage_ok' => is_writable(SNMP_VU_STORAGE_DIR),
    ]);
}

$caps = snmp_vu_snmp_available();
?>
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SNMP VU Monitor</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        :root {
            --vu-bg: linear-gradient(180deg, #0f172a 0%, #111827 100%);
            --vu-panel: #e9eef5;
            --vu-face: linear-gradient(180deg, #fbfbfb 0%, #d8dee8 100%);
            --vu-border: #98a6b8;
            --vu-glow: rgba(59,130,246,.18);
        }
        body { background: radial-gradient(circle at top, #dbeafe 0%, #edf2f7 42%, #d8dee8 100%); min-height: 100vh; padding: 20px; }
        .wrap { max-width: 980px; margin: 0 auto; }
        .hero-card, .config-card, .walk-card { background: rgba(255,255,255,.84); border: 1px solid rgba(148,163,184,.35); border-radius: 22px; box-shadow: 0 16px 45px rgba(15,23,42,.12); backdrop-filter: blur(14px); }
        .hero-card { padding: 20px; }
        .vu-stack { display: grid; gap: 12px; }
        .vu-card { position: relative; display: grid; grid-template-columns: 74px 1fr; gap: 12px; align-items: center; padding: 10px 12px; border-radius: 18px; background: var(--vu-bg); color: #e5eefb; min-height: 116px; box-shadow: inset 0 0 0 1px rgba(255,255,255,.05), 0 8px 18px rgba(15,23,42,.18); overflow: hidden; }
        .vu-card::after { content: ""; position: absolute; inset: 0; background: linear-gradient(135deg, rgba(255,255,255,.08), transparent 38%); pointer-events: none; }
        .vu-led { position: absolute; top: 10px; left: 10px; width: 12px; height: 12px; border-radius: 50%; box-shadow: 0 0 14px currentColor; }
        .vu-led.green { color: #22c55e; background: #22c55e; }
        .vu-led.red { color: #ef4444; background: #ef4444; }
        .vu-meter { height: 88px; width: 46px; border-radius: 12px; padding: 6px; background: var(--vu-face); border: 1px solid var(--vu-border); box-shadow: inset 0 0 14px rgba(15,23,42,.12); }
        .vu-track { height: 100%; width: 100%; border-radius: 9px; background: linear-gradient(180deg, #ef4444 0%, #f59e0b 36%, #84cc16 100%); display: flex; align-items: flex-end; overflow: hidden; position: relative; }
        .vu-fill { width: 100%; background: linear-gradient(180deg, rgba(17,24,39,.1), rgba(17,24,39,.82)); transition: height .6s ease; box-shadow: inset 0 1px 0 rgba(255,255,255,.35); }
        .vu-info { display: grid; gap: 4px; }
        .vu-title { font-weight: 800; letter-spacing: .04em; text-transform: uppercase; font-size: .85rem; padding-left: 10px; }
        .vu-readout { font-family: "Segoe UI", sans-serif; font-size: 1.35rem; font-weight: 800; line-height: 1; }
        .vu-sub { color: #94a3b8; font-size: .82rem; }
        .vu-scale { font-size: .75rem; color: #cbd5e1; }
        .pill { display: inline-flex; align-items: center; gap: 8px; border-radius: 999px; padding: 8px 12px; font-size: .86rem; font-weight: 700; background: rgba(15,23,42,.05); }
        .config-card, .walk-card { padding: 18px; margin-top: 18px; }
        .cfg-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 14px; }
        .cfg-box { background: #f8fafc; border: 1px solid #dbe2ec; border-radius: 18px; padding: 14px; }
        .cfg-box h6 { font-weight: 800; margin-bottom: 12px; }
        .form-label { font-size: .78rem; font-weight: 700; text-transform: uppercase; color: #475569; }
        .help-box { border-radius: 16px; background: linear-gradient(135deg, #eff6ff, #e2e8f0); border: 1px solid #bfdbfe; padding: 14px; }
        .walk-output { min-height: 240px; white-space: pre-wrap; background: #0f172a; color: #dbeafe; border-radius: 16px; padding: 14px; font-family: Consolas, monospace; }
        @media (max-width: 720px) {
            .vu-card { grid-template-columns: 62px 1fr; min-height: 108px; }
            .vu-meter { width: 40px; height: 82px; }
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="hero-card">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
            <div>
                <div class="text-uppercase text-muted fw-bold small">Red / SNMP</div>
                <h2 class="mb-1">Gadget de 5 VU Verticales</h2>
                <div class="text-muted">Monitorea tráfico SNMP y ping por cada instrumento. Escala configurable en Mb/s.</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="pill">SNMP PHP: <strong><?= $caps['php'] ? 'SI' : 'NO' ?></strong></span>
                <span class="pill">snmpwalk: <strong><?= $caps['walk_bin'] !== '' ? 'SI' : 'NO' ?></strong></span>
                <span class="pill">Storage: <strong><?= is_writable(SNMP_VU_STORAGE_DIR) ? 'OK' : 'ERROR' ?></strong></span>
            </div>
        </div>
        <?php if (!$caps['ok']): ?>
            <div class="alert alert-warning mt-3 mb-0">
                No hay soporte SNMP activo en este servidor. Instale <code>php-snmp</code> o los paquetes <code>snmp/snmpwalk</code> en Ubuntu para que el polling funcione.
            </div>
        <?php endif; ?>
        <div class="help-box mt-3">
            <strong>Ayuda rápida MikroTik:</strong>
            Use SNMP walk sobre <code>1.3.6.1.2.1.2.2.1</code> o <code>1.3.6.1.2.1.31.1.1.1</code> para descubrir puertos. Para tráfico:
            <code>ifInOctets</code> <code>1.3.6.1.2.1.2.2.1.10.X</code> y
            <code>ifOutOctets</code> <code>1.3.6.1.2.1.2.2.1.16.X</code>. En modo <code>counter_bytes</code> el gadget calcula Mb/s a partir del delta.
        </div>
        <div class="vu-stack mt-3" id="vuStack">
            <?php foreach ($config['items'] as $i => $item): ?>
                <div class="vu-card" data-index="<?= $i ?>">
                    <span class="vu-led red" id="vu-led-<?= $i ?>"></span>
                    <div class="vu-meter">
                        <div class="vu-track">
                            <div class="vu-fill" id="vu-fill-<?= $i ?>" style="height:100%"></div>
                        </div>
                    </div>
                    <div class="vu-info">
                        <div class="vu-title"><?= htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="vu-readout"><span id="vu-value-<?= $i ?>">0.00</span> <span style="font-size:.82rem">Mb/s</span></div>
                        <div class="vu-scale">Escala: <?= htmlspecialchars((string)$item['scale_mbps'], ENT_QUOTES, 'UTF-8') ?> Mb/s</div>
                        <div class="vu-sub" id="vu-meta-<?= $i ?>">Esperando datos...</div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="config-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h4 class="mb-0">Configuración</h4>
            <div class="d-flex gap-2">
                <input type="number" class="form-control" style="width:140px" id="refreshMs" value="<?= (int)$config['refresh_ms'] ?>" min="1000" step="500">
                <button class="btn btn-primary" onclick="saveConfig()"><i class="fas fa-save me-1"></i> Guardar</button>
            </div>
        </div>
        <div class="cfg-grid">
            <?php foreach ($config['items'] as $i => $item): ?>
                <div class="cfg-box">
                    <h6>Instrumento <?= $i + 1 ?></h6>
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="enabled_<?= $i ?>" <?= !empty($item['enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="enabled_<?= $i ?>">Activo</label>
                    </div>
                    <label class="form-label">Etiqueta</label>
                    <input class="form-control form-control-sm mb-2" id="label_<?= $i ?>" value="<?= htmlspecialchars((string)$item['label'], ENT_QUOTES, 'UTF-8') ?>">
                    <label class="form-label">Host</label>
                    <input class="form-control form-control-sm mb-2" id="host_<?= $i ?>" value="<?= htmlspecialchars((string)$item['host'], ENT_QUOTES, 'UTF-8') ?>">
                    <label class="form-label">Community</label>
                    <input class="form-control form-control-sm mb-2" id="community_<?= $i ?>" value="<?= htmlspecialchars((string)$item['community'], ENT_QUOTES, 'UTF-8') ?>">
                    <label class="form-label">Version</label>
                    <select class="form-select form-select-sm mb-2" id="version_<?= $i ?>">
                        <option value="2c" <?= $item['version'] === '2c' ? 'selected' : '' ?>>SNMP v2c</option>
                        <option value="1" <?= $item['version'] === '1' ? 'selected' : '' ?>>SNMP v1</option>
                    </select>
                    <label class="form-label">OID</label>
                    <input class="form-control form-control-sm mb-2" id="oid_<?= $i ?>" value="<?= htmlspecialchars((string)$item['oid'], ENT_QUOTES, 'UTF-8') ?>" placeholder="1.3.6.1...">
                    <label class="form-label">Modo</label>
                    <select class="form-select form-select-sm mb-2" id="mode_<?= $i ?>">
                        <option value="counter_bytes" <?= $item['mode'] === 'counter_bytes' ? 'selected' : '' ?>>Contador bytes -> Mb/s</option>
                        <option value="counter_bits" <?= $item['mode'] === 'counter_bits' ? 'selected' : '' ?>>Contador bits -> Mb/s</option>
                        <option value="direct_bps" <?= $item['mode'] === 'direct_bps' ? 'selected' : '' ?>>Valor directo en bps</option>
                        <option value="direct_mbps" <?= $item['mode'] === 'direct_mbps' ? 'selected' : '' ?>>Valor directo en Mb/s</option>
                    </select>
                    <label class="form-label">Escala max Mb/s</label>
                    <input type="number" class="form-control form-control-sm mb-2" id="scale_<?= $i ?>" value="<?= htmlspecialchars((string)$item['scale_mbps'], ENT_QUOTES, 'UTF-8') ?>" step="0.1" min="1">
                    <label class="form-label">IP Ping / LED</label>
                    <input class="form-control form-control-sm mb-2" id="ping_<?= $i ?>" value="<?= htmlspecialchars((string)$item['ping_ip'], ENT_QUOTES, 'UTF-8') ?>">
                    <label class="form-label">OID para snmpwalk</label>
                    <input class="form-control form-control-sm mb-2" id="walk_<?= $i ?>" value="<?= htmlspecialchars((string)$item['walk_oid'], ENT_QUOTES, 'UTF-8') ?>" placeholder="1.3.6.1.2.1.2.2.1">
                    <button class="btn btn-outline-secondary btn-sm w-100" onclick="runWalk(<?= $i ?>)"><i class="fas fa-network-wired me-1"></i> Ejecutar SNMP walk</button>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="walk-card">
        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
            <h4 class="mb-0">Salida SNMP walk</h4>
            <div class="small text-muted">Use esta salida para identificar índices de puertos Ethernet o WiFi en MikroTik.</div>
        </div>
        <div class="walk-output" id="walkOutput">Sin ejecutar.</div>
    </div>
</div>

<script src="assets/js/bootstrap.bundle.min.js"></script>
<script>
const pollUrl = 'snmp_vu_monitor.php?action=poll';
let pollTimer = null;

function getItemPayload(index) {
    return {
        enabled: document.getElementById(`enabled_${index}`).checked ? 1 : 0,
        label: document.getElementById(`label_${index}`).value,
        host: document.getElementById(`host_${index}`).value,
        community: document.getElementById(`community_${index}`).value,
        version: document.getElementById(`version_${index}`).value,
        oid: document.getElementById(`oid_${index}`).value,
        mode: document.getElementById(`mode_${index}`).value,
        scale_mbps: document.getElementById(`scale_${index}`).value,
        ping_ip: document.getElementById(`ping_${index}`).value,
        walk_oid: document.getElementById(`walk_${index}`).value
    };
}

function setVu(index, item) {
    const fill = document.getElementById(`vu-fill-${index}`);
    const led = document.getElementById(`vu-led-${index}`);
    const value = document.getElementById(`vu-value-${index}`);
    const meta = document.getElementById(`vu-meta-${index}`);
    if (fill) fill.style.height = `${100 - (item.percent || 0)}%`;
    if (value) value.textContent = Number(item.mbps || 0).toFixed(2);
    if (meta) meta.textContent = item.enabled ? `${item.msg} | Raw: ${item.raw ?? '-'} | Escala ${item.scale_mbps} Mb/s` : 'Desactivado';
    if (led) {
        led.classList.remove('green', 'red');
        led.classList.add(item.ping_ok ? 'green' : 'red');
    }
}

async function pollNow() {
    try {
        const res = await fetch(pollUrl, { cache: 'no-store' });
        const data = await res.json();
        if (data.status !== 'success') return;
        (data.items || []).forEach((item, index) => setVu(index, item));
    } catch (err) {
        console.error(err);
    }
}

function startPolling() {
    if (pollTimer) clearInterval(pollTimer);
    const ms = Math.max(1000, parseInt(document.getElementById('refreshMs').value || '3000', 10));
    pollNow();
    pollTimer = setInterval(pollNow, ms);
}

async function saveConfig() {
    const fd = new FormData();
    fd.append('action', 'save_config');
    fd.append('refresh_ms', document.getElementById('refreshMs').value);
    for (let i = 0; i < 5; i++) {
        const item = getItemPayload(i);
        Object.entries(item).forEach(([key, value]) => fd.append(`items[${i}][${key}]`, value));
    }
    const res = await fetch('snmp_vu_monitor.php', { method: 'POST', body: fd });
    const data = await res.json();
    alert(data.msg || 'Guardado');
    if (data.status === 'success') startPolling();
}

async function runWalk(index) {
    const fd = new FormData();
    fd.append('action', 'walk');
    const item = getItemPayload(index);
    Object.entries(item).forEach(([key, value]) => fd.append(key, value));
    const output = document.getElementById('walkOutput');
    output.textContent = 'Ejecutando...';
    try {
        const res = await fetch('snmp_vu_monitor.php', { method: 'POST', body: fd });
        const data = await res.json();
        output.textContent = data.status === 'success' ? (data.output || 'Sin salida') : (data.msg || 'Error');
    } catch (err) {
        output.textContent = 'Error ejecutando walk';
    }
}

startPolling();
</script>
<?php include_once 'menu_master.php'; ?>
</body>
</html>
