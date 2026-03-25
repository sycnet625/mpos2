<?php
$botBridgeChatsFile = (string)bot_context_get('bridge_chats_file', '');
$botPromoTemplatesFile = (string)bot_context_get('promo_templates_file', '');
$botPromoGroupListsFile = (string)bot_context_get('promo_group_lists_file', '');
$botPromoQueueFile = (string)bot_context_get('promo_queue_file', '');

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_chats') {
    $q = trim((string)($_GET['q'] ?? ''));
    $search = function_exists('mb_strtolower') ? mb_strtolower($q, 'UTF-8') : strtolower($q);
    $data = bot_read_json_file($botBridgeChatsFile, ['updated_at' => null, 'rows' => []]);
    if (empty($data['rows']) || !is_array($data['rows'])) {
        $fallbackRows = $pdo->query("SELECT wa_user_id, wa_name, last_seen FROM pos_bot_sessions ORDER BY last_seen DESC LIMIT 120")->fetchAll(PDO::FETCH_ASSOC);
        $data = [
            'updated_at' => date('c'),
            'rows' => array_map(static function(array $row): array {
                $id = substr(trim((string)($row['wa_user_id'] ?? '')), 0, 120);
                return [
                    'id' => $id,
                    'name' => substr(trim((string)($row['wa_name'] ?? $id)), 0, 200),
                    'is_group' => 0,
                    'is_contact' => 1,
                ];
            }, $fallbackRows ?: [])
        ];
    }
    $rows = [];
    foreach (($data['rows'] ?? []) as $r) {
        $id = substr(trim((string)($r['id'] ?? '')), 0, 120);
        if ($id === '') continue;
        $name = substr(trim((string)($r['name'] ?? $id)), 0, 200);
        if ($search !== '') {
            $haystackId = function_exists('mb_strtolower') ? mb_strtolower($id, 'UTF-8') : strtolower($id);
            $haystackName = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
            if (strpos($haystackId, $search) === false && strpos($haystackName, $search) === false) {
                continue;
            }
        }
        $rows[] = [
            'id' => $id,
            'name' => $name,
            'is_group' => !empty($r['is_group']) ? 1 : 0,
            'is_contact' => !empty($r['is_contact']) ? 1 : 0,
        ];
    }
    usort($rows, static function ($a, $b) {
        if ((int)$a['is_group'] !== (int)$b['is_group']) return (int)$b['is_group'] <=> (int)$a['is_group'];
        return strcasecmp((string)$a['name'], (string)$b['name']);
    });
    echo json_encode(['status' => 'success', 'updated_at' => $data['updated_at'] ?? null, 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_products') {
    $q = trim((string)($_GET['q'] ?? ''));
    $lim = max(1, min(30, (int)($_GET['limit'] ?? 20)));
    $like = '%' . $q . '%';
    $hasWholesale = bot_has_column($pdo, 'productos', 'precio_mayorista');
    $idEmpresa = (int)($config['id_empresa'] ?? 0);
    $sql = "SELECT codigo,nombre,precio," . ($hasWholesale ? "COALESCE(precio_mayorista,0)" : "0") . " AS precio_mayorista
            FROM productos
            WHERE activo=1";
    $params = [];
    if ($idEmpresa > 0) {
        $sql .= " AND id_empresa=?";
        $params[] = $idEmpresa;
    }
    $sql .= " AND (nombre LIKE ? OR codigo LIKE ?)
            ORDER BY nombre ASC
            LIMIT {$lim}";
    $st = $pdo->prepare($sql);
    $params[] = $like;
    $params[] = $like;
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    $out = [];
    $base = bot_public_base_url();
    foreach ($rows as $r) {
        $sku = (string)$r['codigo'];
        $img = $base . "/image.php?code=" . rawurlencode($sku);
        $out[] = [
            'id' => $sku,
            'name' => (string)$r['nombre'],
            'price' => (float)$r['precio'],
            'retail_price' => (float)$r['precio'],
            'wholesale_price' => (float)($r['precio_mayorista'] ?? 0),
            'price_mode' => 'retail',
            'image' => $img
        ];
    }
    echo json_encode(['status'=>'success','rows'=>$out]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_my_group_payload') {
    $payload = bot_my_group_campaign_payload($pdo, $config);
    echo json_encode([
        'status' => 'success',
        'products' => $payload['products'],
        'reservables' => $payload['reservables'],
        'outro_text' => $payload['outro_text']
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_templates') {
    $data = bot_read_json_file($botPromoTemplatesFile, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    usort($rows, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    echo json_encode(['status' => 'success', 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_group_lists') {
    $data = bot_read_json_file($botPromoGroupListsFile, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    usort($rows, static fn($a, $b) => strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? '')));
    foreach ($rows as &$row) {
        $row['id'] = substr(trim((string)($row['id'] ?? '')), 0, 80);
        $name = substr(trim((string)($row['name'] ?? '')), 0, 120);
        $row['name'] = $name !== '' ? $name : 'Lista sin nombre';
        $targets = is_array($row['targets'] ?? null) ? $row['targets'] : [];
        $row['targets'] = array_values(array_filter(array_map(static function($t){
            $id = substr(trim((string)($t['id'] ?? $t)), 0, 120);
            if ($id === '') return null;
            $name = substr(trim((string)($t['name'] ?? $id)), 0, 200);
            return ['id' => $id, 'name' => $name !== '' ? $name : $id];
        }, $targets), static fn($x) => is_array($x) && (trim((string)($x['id'] ?? '')) !== '')));
    }
    unset($row);
    echo json_encode(['status' => 'success', 'rows' => $rows]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_group_list_save') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $listId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    $name = substr(trim((string)($in['name'] ?? '')), 0, 120);
    $targetsInput = is_array($in['targets'] ?? null) ? $in['targets'] : [];
    if ($name === '') {
        echo json_encode(['status' => 'error', 'msg' => 'Nombre de lista obligatorio']);
        exit;
    }
    if (count($targetsInput) === 0) {
        echo json_encode(['status' => 'error', 'msg' => 'La lista no tiene destinos']);
        exit;
    }

    $targets = [];
    $seen = [];
    foreach ($targetsInput as $t) {
        $id = substr(trim((string)($t['id'] ?? $t)), 0, 120);
        if ($id === '' || !empty($seen[$id])) continue;
        $seen[$id] = true;
        $targets[] = [
            'id' => $id,
            'name' => substr(trim((string)($t['name'] ?? $id)), 0, 200)
        ];
    }
    if (count($targets) === 0) {
        echo json_encode(['status' => 'error', 'msg' => 'La lista no tiene destinos']);
        exit;
    }

    $data = bot_read_json_file($botPromoGroupListsFile, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $now = date('c');
    $updated = false;
    if ($listId !== '') {
        foreach ($rows as &$row) {
            if ((string)($row['id'] ?? '') !== $listId) continue;
            $row['name'] = $name;
            $row['targets'] = $targets;
            $row['updated_at'] = $now;
            $updated = true;
            break;
        }
        unset($row);
    }
    if (!$updated) {
        $listId = 'list_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
        $rows[] = [
            'id' => $listId,
            'name' => $name,
            'targets' => $targets,
            'created_at' => $now,
            'updated_at' => $now,
            'created_by' => $_SESSION['admin_name'] ?? 'admin'
        ];
    }

    if (!bot_write_json_file($botPromoGroupListsFile, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar la lista']);
        exit;
    }
    echo json_encode(['status' => 'success', 'id' => $listId]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_group_list_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $listId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($listId === '') {
        echo json_encode(['status' => 'error', 'msg' => 'id requerido']);
        exit;
    }
    $data = bot_read_json_file($botPromoGroupListsFile, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $filtered = array_values(array_filter($rows, static fn($r) => (string)($r['id'] ?? '') !== $listId));
    if (count($filtered) === count($rows)) {
        echo json_encode(['status' => 'error', 'msg' => 'Lista no encontrada']);
        exit;
    }
    if (!bot_write_json_file($botPromoGroupListsFile, ['rows' => $filtered])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo eliminar la lista']);
        exit;
    }
    echo json_encode(['status' => 'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_template_save') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = substr(trim((string)($in['name'] ?? '')), 0, 120);
    $text = trim((string)($in['text'] ?? ''));
    $products = is_array($in['products'] ?? null) ? $in['products'] : [];
    $bannerImages = is_array($in['banner_images'] ?? null) ? $in['banner_images'] : [];
    $templateId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($name === '') { echo json_encode(['status' => 'error', 'msg' => 'Nombre de plantilla obligatorio']); exit; }
    if ($text === '' && count($products) === 0 && count($bannerImages) === 0) { echo json_encode(['status' => 'error', 'msg' => 'Plantilla vacía']); exit; }

    $cleanProducts = [];
    foreach ($products as $p) {
        $pid = substr(trim((string)($p['id'] ?? '')), 0, 80);
        if ($pid === '') continue;
        $cleanProducts[] = [
            'id' => $pid,
            'name' => substr(trim((string)($p['name'] ?? $pid)), 0, 150),
            'price' => (float)($p['price'] ?? 0),
            'retail_price' => (float)($p['retail_price'] ?? ($p['price'] ?? 0)),
            'wholesale_price' => (float)($p['wholesale_price'] ?? 0),
            'price_mode' => in_array((string)($p['price_mode'] ?? 'retail'), ['retail','wholesale'], true) ? (string)$p['price_mode'] : 'retail',
            'image' => trim((string)($p['image'] ?? ''))
        ];
    }
    $cleanBannerImages = bot_normalize_banner_images($bannerImages);
    $overwriteExisting = !empty($in['overwrite_existing']);

    $data = bot_read_json_file($botPromoTemplatesFile, ['rows' => []]);
    $rows = is_array($data['rows'] ?? null) ? $data['rows'] : [];
    $nowIso = date('c');
    $nameNorm = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $conflictId = '';
    foreach ($rows as $row) {
        $rowName = trim((string)($row['name'] ?? ''));
        $rowNorm = function_exists('mb_strtolower') ? mb_strtolower($rowName, 'UTF-8') : strtolower($rowName);
        $rowId = (string)($row['id'] ?? '');
        if ($rowNorm === $nameNorm && $rowId !== $templateId) {
            $conflictId = $rowId;
            break;
        }
    }
    if ($conflictId !== '') {
        if (!$overwriteExisting) {
            echo json_encode(['status' => 'error', 'msg' => 'Ya existe una plantilla con ese nombre', 'code' => 'duplicate_name', 'duplicate_id' => $conflictId]); exit;
        }
        $templateId = $conflictId;
    }
    if ($templateId === '') $templateId = 'tpl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));

    $updated = false;
    foreach ($rows as &$row) {
        if ((string)($row['id'] ?? '') !== $templateId) continue;
        $row['name'] = $name;
        $row['text'] = $text;
        $row['products'] = $cleanProducts;
        $row['banner_images'] = $cleanBannerImages;
        $row['updated_at'] = $nowIso;
        $updated = true;
        break;
    }
    unset($row);
    if (!$updated) {
        $rows[] = [
            'id' => $templateId,
            'name' => $name,
            'text' => $text,
            'products' => $cleanProducts,
            'banner_images' => $cleanBannerImages,
            'updated_at' => $nowIso,
            'created_by' => $_SESSION['admin_name'] ?? 'admin'
        ];
    }
    if (!bot_write_json_file($botPromoTemplatesFile, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar plantilla']); exit;
    }
    echo json_encode(['status' => 'success', 'id' => $templateId]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_upload_image') {
    if (empty($_FILES['image']) || !is_array($_FILES['image'])) {
        echo json_encode(['status' => 'error', 'msg' => 'Imagen requerida']); exit;
    }
    $file = $_FILES['image'];
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        echo json_encode(['status' => 'error', 'msg' => 'Error al subir imagen']); exit;
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        echo json_encode(['status' => 'error', 'msg' => 'Carga inválida']); exit;
    }
    if ((int)($file['size'] ?? 0) > 5 * 1024 * 1024) {
        echo json_encode(['status' => 'error', 'msg' => 'La imagen supera 5MB']); exit;
    }
    $mime = (string)(mime_content_type($tmp) ?: '');
    $allowed = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
        'image/gif' => 'gif'
    ];
    if (!isset($allowed[$mime])) {
        echo json_encode(['status' => 'error', 'msg' => 'Formato no permitido']); exit;
    }
    $dir = POSBOT_API_ROOT . '/uploads/promo_campaigns';
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo crear carpeta de banners']); exit;
    }
    $nameSafe = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)($file['name'] ?? 'banner')) ?: 'banner';
    $baseName = pathinfo($nameSafe, PATHINFO_FILENAME);
    $ext = $allowed[$mime];
    $fileName = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '_' . substr($baseName, 0, 40) . '.' . $ext;
    $target = $dir . '/' . $fileName;
    if (!@move_uploaded_file($tmp, $target)) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo guardar la imagen']); exit;
    }
    @chmod($target, 0664);
    echo json_encode([
        'status' => 'success',
        'url' => bot_public_base_url() . '/uploads/promo_campaigns/' . rawurlencode($fileName),
        'name' => $fileName
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_template_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $templateId = substr(trim((string)($in['id'] ?? '')), 0, 80);
    if ($templateId === '') { echo json_encode(['status' => 'error', 'msg' => 'id requerido']); exit; }
    $data = bot_read_json_file($botPromoTemplatesFile, ['rows' => []]);
    $beforeTpl = count((array)($data['rows'] ?? []));
    $rows = array_values(array_filter((array)($data['rows'] ?? []), static fn($r) => (string)($r['id'] ?? '') !== $templateId));
    if ($beforeTpl === count($rows)) { echo json_encode(['status' => 'error', 'msg' => 'Plantilla no encontrada']); exit; }
    if (!bot_write_json_file($botPromoTemplatesFile, ['rows' => $rows])) {
        echo json_encode(['status' => 'error', 'msg' => 'No se pudo borrar plantilla']); exit;
    }
    $queue = bot_read_json_file($botPromoQueueFile, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $beforeJobs = count($jobs);
    $jobs = array_values(array_filter($jobs, static fn($j) => (string)($j['template_id'] ?? '') !== $templateId));
    $deletedCampaigns = $beforeJobs - count($jobs);
    if ($deletedCampaigns > 0) {
        if (!bot_write_json_file($botPromoQueueFile, ['jobs' => $jobs])) {
            echo json_encode(['status' => 'error', 'msg' => 'La plantilla se borró, pero no se pudieron eliminar las campañas enlazadas']); exit;
        }
    }
    echo json_encode(['status' => 'success', 'deleted_campaigns' => $deletedCampaigns]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_list') {
    $queue = bot_read_json_file($botPromoQueueFile, ['jobs' => []]);
    $jobs = array_slice(array_reverse($queue['jobs'] ?? []), 0, 25);
    echo json_encode(['status' => 'success', 'rows' => $jobs]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='GET' && $action==='promo_detail') {
    $jobId = substr(trim((string)($_GET['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($botPromoQueueFile, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    foreach ($jobs as $job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        echo json_encode(['status' => 'success', 'row' => $job]); exit;
    }
    echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_force_now') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($botPromoQueueFile, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $found = false;
    $now = time();
    foreach ($jobs as &$job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $found = true;
        $wasDone = (($job['status'] ?? '') === 'done');
        $reachedEnd = ((int)($job['current_index'] ?? 0) >= count((array)($job['targets'] ?? [])));
        $job['status'] = 'queued';
        $job['next_run_at'] = $now;
        $job['forced_at'] = date('c', $now);
        $job['last_schedule_key'] = '';
        if ($wasDone || $reachedEnd) {
            $job['current_index'] = 0;
        }
        if (!is_array($job['log'] ?? null)) $job['log'] = [];
        $job['log'][] = [
            'at' => date('c', $now),
            'type' => 'forced_start',
            'ok' => true,
            'target_id' => '',
            'target_name' => '',
            'messages_sent' => 0,
            'error' => ''
        ];
        break;
    }
    unset($job);
    if (!$found) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($botPromoQueueFile, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo forzar la campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_update') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }

    $queue = bot_read_json_file($botPromoQueueFile, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $found = false;

    foreach ($jobs as &$job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $found = true;

        if (array_key_exists('name', $in)) {
            $job['name'] = substr(trim((string)$in['name']), 0, 120);
        }
        if (array_key_exists('campaign_group', $in)) {
            $job['campaign_group'] = substr(trim((string)$in['campaign_group']), 0, 80);
            if ($job['campaign_group'] === '') $job['campaign_group'] = 'General';
        }
        if (array_key_exists('schedule_enabled', $in)) {
            $job['schedule_enabled'] = !empty($in['schedule_enabled']) ? 1 : 0;
        }
        if (array_key_exists('schedule_time', $in)) {
            $tm = substr(trim((string)$in['schedule_time']), 0, 5);
            if ($tm !== '' && !preg_match('/^\d{2}:\d{2}$/', $tm)) {
                echo json_encode(['status'=>'error','msg'=>'Hora inválida (HH:MM)']); exit;
            }
            $job['schedule_time'] = $tm;
        }
        if (array_key_exists('schedule_days', $in)) {
            $daysIn = is_array($in['schedule_days']) ? $in['schedule_days'] : [];
            $days = [];
            foreach ($daysIn as $d) {
                $n = (int)$d;
                if ($n >= 0 && $n <= 6) $days[$n] = $n;
            }
            $days = array_values($days);
            sort($days);
            $job['schedule_days'] = $days;
        }
        if (array_key_exists('status', $in)) {
            $status = strtolower(trim((string)$in['status']));
            if (in_array($status, ['scheduled','queued','running','paused','done','error'], true)) {
                $job['status'] = $status;
            }
        }

        if (!array_key_exists('next_run_at', $job) || !is_numeric($job['next_run_at'])) {
            $job['next_run_at'] = time() + 20;
        }
        if ((int)($job['schedule_enabled'] ?? 0) === 1 && ($job['status'] ?? '') === 'done') {
            $job['status'] = 'scheduled';
            $job['current_index'] = 0;
            $job['next_run_at'] = time() + 20;
        }
        break;
    }
    unset($job);

    if (!$found) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($botPromoQueueFile, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo actualizar campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_delete') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($botPromoQueueFile, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $before = count($jobs);
    $jobs = array_values(array_filter($jobs, static fn($j) => (string)($j['id'] ?? '') !== $jobId));
    if ($before === count($jobs)) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    if (!bot_write_json_file($botPromoQueueFile, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo eliminar campaña']); exit;
    }
    echo json_encode(['status'=>'success']); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_clone') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $jobId = substr(trim((string)($in['id'] ?? '')), 0, 120);
    if ($jobId === '') { echo json_encode(['status'=>'error','msg'=>'id requerido']); exit; }
    $queue = bot_read_json_file($botPromoQueueFile, ['jobs' => []]);
    $jobs = is_array($queue['jobs'] ?? null) ? $queue['jobs'] : [];
    $source = null;
    foreach ($jobs as $job) {
        if ((string)($job['id'] ?? '') !== $jobId) continue;
        $source = $job;
        break;
    }
    if (!$source) { echo json_encode(['status'=>'error','msg'=>'Campaña no encontrada']); exit; }
    $clone = bot_clone_promo_job($source);
    $jobs[] = $clone;
    if (!bot_write_json_file($botPromoQueueFile, ['jobs' => $jobs])) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo clonar campaña']); exit;
    }
    echo json_encode(['status'=>'success','id'=>$clone['id'],'name'=>$clone['name']]); exit;
}

if ($_SERVER['REQUEST_METHOD']==='POST' && $action==='promo_create') {
    $in = json_decode(file_get_contents('php://input'), true) ?: [];
    $text = trim((string)($in['text'] ?? ''));
    $outroText = trim((string)($in['outro_text'] ?? ''));
    $targets = is_array($in['targets'] ?? null) ? $in['targets'] : [];
    $products = is_array($in['products'] ?? null) ? $in['products'] : [];
    $bannerImages = is_array($in['banner_images'] ?? null) ? $in['banner_images'] : [];
    $minSec = max(60, min(180, (int)($in['min_seconds'] ?? 60)));
    $maxSec = max($minSec, min(300, (int)($in['max_seconds'] ?? 120)));
    $campaignName = substr(trim((string)($in['campaign_name'] ?? '')), 0, 120);
    $campaignGroup = substr(trim((string)($in['campaign_group'] ?? 'General')), 0, 80);
    $scheduleTime = substr(trim((string)($in['schedule_time'] ?? '')), 0, 5);
    $scheduleDays = is_array($in['schedule_days'] ?? null) ? $in['schedule_days'] : [];
    $templateId = substr(trim((string)($in['template_id'] ?? '')), 0, 80);
    $scheduleEnabled = !empty($in['schedule_enabled']) ? 1 : 0;

    if ($templateId !== '' && ($text === '' || count($products) === 0)) {
        $tplData = bot_read_json_file($botPromoTemplatesFile, ['rows' => []]);
        foreach (($tplData['rows'] ?? []) as $tpl) {
            if ((string)($tpl['id'] ?? '') !== $templateId) continue;
            if ($text === '') $text = trim((string)($tpl['text'] ?? ''));
            if (count($products) === 0 && is_array($tpl['products'] ?? null)) $products = $tpl['products'];
            if (count($bannerImages) === 0 && is_array($tpl['banner_images'] ?? null)) $bannerImages = $tpl['banner_images'];
            if ($campaignName === '') $campaignName = substr(trim((string)($tpl['name'] ?? '')), 0, 120);
            break;
        }
    }

    $cleanTargetsMap = [];
    foreach ($targets as $t) {
        $id = substr(trim((string)($t['id'] ?? $t)), 0, 120);
        if ($id === '') continue;
        $name = substr(trim((string)($t['name'] ?? $id)), 0, 200);
        $cleanTargetsMap[$id] = $name !== '' ? $name : $id;
    }
    $targetsFinal = [];
    foreach ($cleanTargetsMap as $id => $name) $targetsFinal[] = ['id' => $id, 'name' => $name];

    $cleanProducts = [];
    foreach ($products as $p) {
        $pid = substr(trim((string)($p['id'] ?? '')), 0, 80);
        if ($pid === '') continue;
        $cleanProducts[] = [
            'id' => $pid,
            'name' => substr(trim((string)($p['name'] ?? $pid)), 0, 150),
            'price' => (float)($p['price'] ?? 0),
            'retail_price' => (float)($p['retail_price'] ?? ($p['price'] ?? 0)),
            'wholesale_price' => (float)($p['wholesale_price'] ?? 0),
            'price_mode' => in_array((string)($p['price_mode'] ?? 'retail'), ['retail','wholesale'], true) ? (string)$p['price_mode'] : 'retail',
            'image' => trim((string)($p['image'] ?? ('image.php?code=' . rawurlencode($pid))))
        ];
    }
    $cleanBannerImages = bot_normalize_banner_images($bannerImages);

    if ($text === '' && $outroText === '' && count($cleanProducts) === 0 && count($cleanBannerImages) === 0) { echo json_encode(['status'=>'error','msg'=>'La campaña está vacía']); exit; }
    if (count($targetsFinal) === 0) { echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un grupo/chat']); exit; }

    $daysFinal = [];
    foreach ($scheduleDays as $d) {
        $n = (int)$d;
        if ($n >= 0 && $n <= 6) $daysFinal[$n] = $n;
    }
    $daysFinal = array_values($daysFinal);
    sort($daysFinal);
    if ($scheduleEnabled) {
        if (!preg_match('/^\d{2}:\d{2}$/', $scheduleTime)) {
            echo json_encode(['status'=>'error','msg'=>'Hora inválida (HH:MM)']); exit;
        }
        if (count($daysFinal) === 0) {
            echo json_encode(['status'=>'error','msg'=>'Selecciona al menos un día']); exit;
        }
    } else {
        $scheduleTime = '';
        $daysFinal = [];
    }

    $queue = bot_read_json_file($botPromoQueueFile, ['jobs' => []]);
    $jobId = 'promo_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3));
    $now = time();
    $queue['jobs'][] = [
        'id' => $jobId,
        'status' => $scheduleEnabled ? 'scheduled' : 'queued',
        'created_at' => date('c', $now),
        'created_by' => $_SESSION['admin_name'] ?? 'admin',
        'name' => $campaignName !== '' ? $campaignName : ('Campaña ' . date('d/m H:i')),
        'campaign_group' => $campaignGroup !== '' ? $campaignGroup : 'General',
        'template_id' => $templateId,
        'text' => $text,
        'banner_images' => $cleanBannerImages,
        'outro_text' => $outroText,
        'targets' => $targetsFinal,
        'products' => $cleanProducts,
        'min_seconds' => $minSec,
        'max_seconds' => $maxSec,
        'schedule_enabled' => $scheduleEnabled,
        'schedule_time' => $scheduleTime,
        'schedule_days' => $daysFinal,
        'last_schedule_key' => '',
        'next_run_at' => $scheduleEnabled ? ($now + 30) : $now,
        'current_index' => 0,
        'log' => []
    ];
    if (!bot_write_json_file($botPromoQueueFile, $queue)) {
        echo json_encode(['status'=>'error','msg'=>'No se pudo guardar cola de promoción']); exit;
    }
    push_notify(
        $pdo,
        'operador',
        '📣 Campaña programada',
        ($campaignName !== '' ? $campaignName : $jobId) . ' — ' . count($targetsFinal) . ' destino(s)',
        '/marinero/pos_bot.php',
        'bot_campaign_created'
    );
    echo json_encode(['status'=>'success','id'=>$jobId]); exit;
}
