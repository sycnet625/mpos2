<?php
// ARCHIVO: /var/www/palweb/api/pos_import.php
// VERSIÓN MULTISUCURSAL - KARDEX V2 (CORREGIDO 9 PARAMS)

ini_set('display_errors', 1);
error_reporting(E_ALL);

require_once 'db.php';

// Cargar Configuración para valores por defecto y visualización
require_once 'config_loader.php';
// Variables para el HTML
$DISPLAY_SUC = $config['id_sucursal'];
$DISPLAY_ALM = $config['id_almacen'];
$DISPLAY_EMP = $config['id_empresa'];

if (!file_exists('kardex_engine.php')) { die("Error Crítico: Falta 'kardex_engine.php'."); }
require_once 'kardex_engine.php';

function normalizeDate($dateStr) {
    if (empty(trim($dateStr))) return null;
    $dateStr = str_replace(['/', '.'], '-', trim($dateStr));
    $parts = explode('-', $dateStr);
    if (count($parts) === 3 && strlen($parts[0]) <= 2 && strlen($parts[2]) == 4) {
        $dateStr = "{$parts[2]}-{$parts[1]}-{$parts[0]}";
    }
    $timestamp = strtotime($dateStr);
    return $timestamp ? date('Y-m-d', $timestamp) : null;
}

function cleanNum($val) {
    if ($val === '' || $val === null) return null;
    $val = str_replace(',', '.', $val);
    return floatval(preg_replace('/[^0-9.-]/', '', $val));
}

// --- PROCESAMIENTO AJAX ---
if (isset($_GET['action']) && $_GET['action'] == 'process_batch') {
    ob_clean(); 
    header('Content-Type: application/json');
    
    $input = json_decode(file_get_contents('php://input'), true);
    $results = [];
    $isDryRun = $input['dry_run'] ?? false;
    
    $empID = intval($input['id_empresa'] ?? 1);
    $almID = intval($input['id_almacen'] ?? 1);
    // Intentamos obtener sucID del input o fallback a 1
    $sucID = intval($input['id_sucursal'] ?? 1); 

    try {
        if (!$isDryRun) {
            $pdo->beginTransaction();
            $kardex = new KardexEngine($pdo);
        }

        foreach ($input['batch'] as $row) {
            $sku = trim($row[0] ?? '');
            $nombre = trim($row[1] ?? '');
            $log = ['sku' => $sku, 'action' => '', 'stock_upd' => false, 'props_upd' => false, 'type' => []];

            try {
                if (!$sku || !$nombre) throw new Exception("Fila incompleta.");

                $newData = [
                    'precio' => cleanNum($row[2] ?? 0),
                    'costo'  => cleanNum($row[3] ?? 0),
                    'cat'    => trim($row[4] ?? 'General'),
                    'act'    => intval($row[5] ?? 1),
                    'venc'   => normalizeDate($row[7] ?? ''),
                    'elab'   => intval($row[8] ?? 1),
                    'mat'    => intval($row[9] ?? 0),
                    'serv'   => intval($row[10] ?? 0),
                    'coc'    => intval($row[11] ?? 0)
                ];

                if ($newData['mat']) $log['type'][] = 'MP';
                if ($newData['serv']) $log['type'][] = 'SRV';
                if ($newData['elab']) $log['type'][] = 'ELB';
                if ($newData['coc']) $log['type'][] = 'COC';

                $stmt = $pdo->prepare("SELECT * FROM productos WHERE codigo = ? AND id_empresa = ?");
                $stmt->execute([$sku, $empID]);
                $current = $stmt->fetch(PDO::FETCH_ASSOC);

                if ($current) {
                    $log['action'] = 'UPDATE';
                    if ($current['precio'] != $newData['precio'] || $current['nombre'] != $nombre) {
                        $log['props_upd'] = true;
                    }
                    if (!$isDryRun) {
                        $sql = "UPDATE productos SET nombre=?, precio=?, costo=?, categoria=?, activo=?, es_elaborado=?, es_materia_prima=?, es_servicio=?, es_cocina=?, version_row=? WHERE codigo=? AND id_empresa=?";
                        $pdo->prepare($sql)->execute([$nombre, $newData['precio'], $newData['costo'], $newData['cat'], $newData['act'], $newData['elab'], $newData['mat'], $newData['serv'], $newData['coc'], time(), $sku, $empID]);
                    }
                } else {
                    $log['action'] = 'INSERT';
                    if (!$isDryRun) {
                        $sql = "INSERT INTO productos (codigo, nombre, precio, costo, categoria, activo, es_elaborado, es_materia_prima, es_servicio, es_cocina, id_empresa, version_row) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                        $pdo->prepare($sql)->execute([$sku, $nombre, $newData['precio'], $newData['costo'], $newData['cat'], $newData['act'], $newData['elab'], $newData['mat'], $newData['serv'], $newData['coc'], $empID, time()]);
                    }
                }

                if (isset($row[17]) && trim($row[17]) !== '') {
                    $log['stock_upd'] = true;
                    if (!$isDryRun) {
                        $stkNuevo = cleanNum($row[17]);
                        $stmtStock = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto = ? AND id_almacen = ?");
                        $stmtStock->execute([$sku, $almID]);
                        $stkActual = floatval($stmtStock->fetchColumn() ?: 0);
                        $diferencia = $stkNuevo - $stkActual;

                        if (abs($diferencia) > 0.0001) {
                            // KARDEX ACTUALIZADO CON 9 PARAMETROS
                            $kardex->registrarMovimiento(
                                $sku,
                                $almID,
                                $sucID, 
                                'AJUSTE', 
                                $diferencia, 
                                "IMPORT_CSV_" . date('His'), 
                                $newData['costo'],
                                "ADMIN_IMPORT",
                                date('Y-m-d H:i:s') // <--- FECHA AGREGADA
                            );
                        }
                    }
                }
                $log['status'] = 'success';
            } catch (Exception $e) {
                $log['status'] = 'error';
                $log['msg'] = $e->getMessage();
            }
            $results[] = $log;
        }

        if (!$isDryRun) $pdo->commit();
        echo json_encode($results);
    } catch (Exception $e) {
        if (!$isDryRun && $pdo->inTransaction()) $pdo->rollBack();
        echo json_encode([['status' => 'error', 'msg' => 'Error General: ' . $e->getMessage()]]);
    }
    exit;
}
?>

<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8"><title>Importador Maestro (Kardex)</title>
    <link href="assets/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="assets/css/all.min.css">
    <style>
        body { background-color: #f1f5f9; font-family: 'Inter', sans-serif; }
        .card-main { max-width: 1000px; margin: 30px auto; border-radius: 12px; border:none; box-shadow: 0 10px 25px rgba(0,0,0,0.1); }
        .console-log { 
            height: 350px; overflow-y: scroll; background: #0f172a; color: #cbd5e1; 
            font-family: 'Fira Code', 'Courier New', monospace; font-size: 0.8rem; padding: 15px; 
            border-radius: 8px; border: 1px solid #1e293b;
        }
        .log-new { color: #4ade80; } .log-upd { color: #60a5fa; } .log-stk { color: #f59e0b; } 
        .stats-box { background: #fff; border-radius: 8px; padding: 15px; border: 1px solid #e2e8f0; }
        .stat-val { font-size: 1.2rem; font-weight: bold; display: block; }
        .context-badge { background: rgba(255,255,255,0.2); padding: 4px 10px; border-radius: 4px; font-size: 0.85rem; color: #eee; border: 1px solid rgba(255,255,255,0.1); }
    </style>
</head>
<body>

<div class="container-fluid">
    <div class="card card-main">
        <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center py-3">
            <div class="d-flex align-items-center gap-3">
                <h5 class="mb-0"><i class="fas fa-microchip me-2"></i> Importador Maestro</h5>
                <div class="context-badge">
                    <i class="fas fa-building me-1 text-info"></i> Suc: <strong><?php echo $DISPLAY_SUC; ?></strong>
                    <span class="mx-2 opacity-50">|</span>
                    <i class="fas fa-warehouse me-1 text-warning"></i> Alm: <strong><?php echo $DISPLAY_ALM; ?></strong>
                </div>
            </div>
            <div class="form-check form-switch bg-primary px-3 py-1 rounded">
                <input class="form-check-input" type="checkbox" id="dryRun" checked>
                <label class="form-check-label text-white small fw-bold" for="dryRun">SIMULACIÓN</label>
            </div>
        </div>
        
        <div class="card-body p-4">
            <div class="row g-3 mb-4">
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Empresa ID</label>
                    <input type="number" id="empId" class="form-control form-control-sm" value="<?php echo $DISPLAY_EMP; ?>">
                </div>
                <div class="col-md-2">
                    <label class="small fw-bold text-muted">Almacén Destino</label>
                    <input type="number" id="almId" class="form-control form-control-sm" value="<?php echo $DISPLAY_ALM; ?>">
                </div>
                <input type="hidden" id="sucId" value="<?php echo $DISPLAY_SUC; ?>">
                
                <div class="col-md-5">
                    <label class="small fw-bold text-muted">Archivo CSV</label>
                    <input type="file" id="csvFile" class="form-control form-control-sm" accept=".csv">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <button type="button" id="btnStart" class="btn btn-primary w-100 fw-bold shadow-sm">
                        <i class="fas fa-play me-2"></i> INICIAR
                    </button>
                </div>
            </div>

            <div id="statsPanel" class="row g-2 mb-4 d-none">
                <div class="col-md-2 text-center"><div class="stats-box"><small class="text-muted">TOTAL</small><span id="sTotal" class="stat-val">0</span></div></div>
                <div class="col-md-2 text-center"><div class="stats-box"><small class="text-muted">NUEVOS</small><span id="sNew" class="stat-val text-success">0</span></div></div>
                <div class="col-md-2 text-center"><div class="stats-box"><small class="text-muted">ACTUALIZADOS</small><span id="sUpd" class="stat-val text-info">0</span></div></div>
                <div class="col-md-2 text-center"><div class="stats-box"><small class="text-muted">ERRORES</small><span id="sErr" class="stat-val text-danger">0</span></div></div>
            </div>

            <div id="processArea" class="d-none">
                <div class="progress mb-3" style="height: 10px;"><div id="progressBar" class="progress-bar bg-success progress-bar-striped progress-bar-animated" style="width: 0%"></div></div>
                <div id="consoleLog" class="console-log"></div>
            </div>
        </div>
    </div>
</div>

<script>
const log = document.getElementById('consoleLog');
let counts = { total: 0, new: 0, upd: 0, err: 0 };

function writeLog(text, colorClass = '') {
    const div = document.createElement('div');
    div.className = `mb-1 ${colorClass}`;
    div.innerHTML = `[${new Date().toLocaleTimeString()}] ${text}`;
    log.appendChild(div);
    log.scrollTop = log.scrollHeight;
}

function updateStats() {
    document.getElementById('sTotal').innerText = counts.total;
    document.getElementById('sNew').innerText = counts.new;
    document.getElementById('sUpd').innerText = counts.upd;
    document.getElementById('sErr').innerText = counts.err;
}

document.getElementById('btnStart').addEventListener('click', async () => {
    const file = document.getElementById('csvFile').files[0];
    if (!file) return alert("Seleccione un archivo CSV.");

    counts = { total: 0, new: 0, upd: 0, err: 0 };
    updateStats();
    document.getElementById('statsPanel').classList.remove('d-none');
    document.getElementById('processArea').classList.remove('d-none');
    log.innerHTML = "";
    writeLog("--- ANALIZANDO ARCHIVO ---", "text-warning fw-bold");

    const text = await file.text();
    const rows = text.split(/\r?\n/).map(l => {
        const s = l.includes(';') ? ';' : ',';
        return l.split(s).map(c => c.trim().replace(/^"|"$/g, ''));
    }).filter(r => r.length > 1);

    const data = rows.slice(1); // Saltar cabecera
    const batchSize = 20;

    for (let i = 0; i < data.length; i += batchSize) {
        const batch = data.slice(i, i + batchSize);
        const payload = {
            batch,
            dry_run: document.getElementById('dryRun').checked,
            id_empresa: document.getElementById('empId').value,
            id_almacen: document.getElementById('almId').value,
            id_sucursal: document.getElementById('sucId').value // Enviamos la sucursal
        };

        try {
            const resp = await fetch('pos_import.php?action=process_batch', {
                method: 'POST', headers: {'Content-Type': 'application/json'},
                body: JSON.stringify(payload)
            });
            const res = await resp.json();

            if (res[0] && res[0].status === 'error' && res[0].msg.includes('Error General')) {
                 writeLog(`❌ ${res[0].msg}`, 'text-danger'); break;
            }

            res.forEach(item => {
                counts.total++;
                if (item.status === 'error') {
                    counts.err++;
                    writeLog(`❌ SKU ${item.sku}: ${item.msg}`, 'text-danger');
                } else {
                    if (item.action === 'INSERT') counts.new++; else counts.upd++;
                    
                    let msg = `SKU ${item.sku}: `;
                    msg += (item.action === 'INSERT') ? `<span class="log-new">[NUEVO]</span> ` : `<span class="log-upd">[OK]</span> `;
                    if (item.stock_upd) msg += `<span class="log-stk">(Stock)</span>`;
                    writeLog(msg);
                }
            });

            const p = Math.round(((i + batch.length) / data.length) * 100);
            document.getElementById('progressBar').style.width = p + '%';
            updateStats();
        } catch (e) {
            writeLog("❌ Error de comunicación", "text-danger"); break;
        }
    }
    writeLog("--- FINALIZADO ---", "text-success fw-bold");
});
</script>

<?php include_once 'menu_master.php'; ?>
</body>
</html>

