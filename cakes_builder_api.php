<?php
declare(strict_types=1);
ini_set('display_errors', '0');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config_loader.php';
require_once __DIR__ . '/cakes_storage.php';
if (session_status() === PHP_SESSION_NONE) { session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']); session_start(); }
function cake_catalog(): array { return [
    'mini'=>['nombre'=>'Minicake 18 cm','base'=>2000,'kind'=>'custom','coverage'=>['Merengue incluido'=>0,'Nata'=>2000,'Ganache de chocolate'=>3000],'extras'=>['Sin extra'=>0,'Cobertura de bombón'=>500,'Fanguito'=>800,'Nutella'=>900,'Confituras mixtas'=>1000]],
    'cake22'=>['nombre'=>'Cake de 22 cm','base'=>3500,'kind'=>'custom','coverage'=>['Merengue incluido'=>0,'Nata'=>4000,'Ganache de chocolate'=>5000],'extras'=>['Sin extra'=>0,'Cobertura de bombón'=>800,'Fanguito'=>1200,'Nutella'=>1400,'Confituras mixtas'=>1500]],
    'two'=>['nombre'=>'Cake de 2 pisos','base'=>8000,'kind'=>'custom','coverage'=>['Merengue incluido'=>0,'Nata'=>6000,'Ganache de chocolate'=>8000],'extras'=>['Sin extra'=>0,'Cobertura de bombón'=>1400,'Fanguito'=>2100,'Nutella'=>2400,'Confituras mixtas'=>2600]],
    'three'=>['nombre'=>'Cake de 3 pisos','kind'=>'flavor','flavors'=>['Fresa'=>15700,'Vainilla'=>16100,'Vainilla chips'=>16600,'Chocolate'=>17000,'Chocomaní'=>17500,'Bombón'=>18100,'Fresa bombón'=>18500,'Tres delicias'=>18900]],
    'large'=>['nombre'=>'Cake 30 x 40 cm','kind'=>'flavor','flavors'=>['Tres delicias'=>11500,'Fresa bombón'=>11200,'Bombón'=>11000,'Frambuesa'=>10700,'Chocomaní'=>10500,'Chocolate'=>10200,'Vainilla chips'=>9900,'Vainilla'=>9700,'Fresa'=>9500]],
]; }
function quote_cake(array $item, array $catalog): array {
    $model=(string)($item['model']??'');
    if (!isset($catalog[$model])) throw new InvalidArgumentException('Modelo no disponible.');
    $def=$catalog[$model];
    $flavor=trim((string)($item['flavor']??'')); $filling=trim((string)($item['filling']??''));
    $coverage=trim((string)($item['coverage']??'')); $extra=trim((string)($item['extra']??''));
    $color=trim((string)($item['meringue_color']??'Blanco')) ?: 'Blanco';
    if ($def['kind']==='flavor') {
        if (!isset($def['flavors'][$flavor])) throw new InvalidArgumentException('Sabor no disponible.');
        $price=(float)$def['flavors'][$flavor];
    } else {
        if (!isset($def['coverage'][$coverage],$def['extras'][$extra])) throw new InvalidArgumentException('Opción no disponible.');
        if (!in_array($flavor,['Vainilla','Chocolate','Fresa','Mantecado'],true)) throw new InvalidArgumentException('Panetela no disponible.');
        if (!in_array($filling,['Vainilla','Chocolate','Fresa','Mantecado','Limón','Frutas mixtas'],true)) throw new InvalidArgumentException('Relleno no disponible.');
        if ($coverage==='Merengue incluido' && !in_array($color,['Blanco','Azul','Verde','Rojo','Rosado','Naranja','Amarillo','Morado','Celeste','Negro'],true)) throw new InvalidArgumentException('Color de merengue no disponible.');
        $price=(float)$def['base']+(float)$def['coverage'][$coverage]+(float)$def['extras'][$extra];
    }
    $description=$def['nombre'].' · Panetela: '.$flavor;
    if ($def['kind']==='custom') $description.=' · Relleno: '.$filling.' · Cobertura: '.$coverage.($coverage==='Merengue incluido'?' ('.$color.')':'').' · Extra: '.$extra;
    return ['model'=>$model,'nombre'=>$def['nombre'],'flavor'=>$flavor,'filling'=>$def['kind']==='custom'?$filling:null,'coverage'=>$def['kind']==='custom'?$coverage:null,'meringue_color'=>$def['kind']==='custom' && $coverage==='Merengue incluido'?$color:null,'extra'=>$def['kind']==='custom'?$extra:null,'precio'=>$price,'descripcion'=>$description];
}
function ensure_cake_visits_table(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS cakes_web_visits (
        session_id VARCHAR(128) PRIMARY KEY,
        id_empresa INT NOT NULL,
        fecha DATETIME NOT NULL,
        INDEX idx_empresa_fecha (id_empresa, fecha)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}
function register_cake_visit(PDO $pdo, int $empresa): void {
    ensure_cake_visits_table($pdo);
    $sessionId = session_id();
    if ($sessionId === '') return;
    $stmt = $pdo->prepare('INSERT IGNORE INTO cakes_web_visits (session_id, id_empresa, fecha) VALUES (?, ?, ?)');
    $stmt->execute([$sessionId, $empresa, date('Y-m-d H:i:s')]);
}
function cake_activity_count(PDO $pdo, int $empresa): int {
    ensure_cake_visits_table($pdo);
    $visits = $pdo->prepare('SELECT COUNT(*) FROM cakes_web_visits WHERE id_empresa = ?');
    $visits->execute([$empresa]);
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(d.cantidad),0) FROM ventas_cabecera c JOIN ventas_detalle d ON d.id_venta_cabecera=c.id WHERE c.id_empresa = ? AND c.canal_origen = 'Web Cakes'");
    $stmt->execute([$empresa]);
    return (int)$visits->fetchColumn() + (int)$stmt->fetchColumn();
}
try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && (isset($_GET['count']) || isset($_GET['visit']))) {
        $empresa = (int)$config['id_empresa'];
        if (isset($_GET['visit'])) register_cake_visit($pdo, $empresa);
        echo json_encode(['status' => 'success', 'count' => cake_activity_count($pdo, $empresa)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new InvalidArgumentException('Se requiere POST.');
    $in=json_decode(file_get_contents('php://input'),true); if (!is_array($in)) throw new InvalidArgumentException('Solicitud inválida.');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'],(string)($in['csrf_token']??''))) { http_response_code(403); throw new InvalidArgumentException('Token de seguridad inválido.'); }
    $requestId=(string)($in['request_id']??'');
    if ($requestId!=='' && !preg_match('/\A[a-f0-9]{32}\z/', $requestId)) throw new InvalidArgumentException('Identificador de pedido inválido.');
    if ($requestId==='') $requestId=bin2hex(random_bytes(16));
    $payloadForHash=$in; unset($payloadForHash['csrf_token']);
    $payloadHash=hash('sha256',json_encode($payloadForHash,JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    $tokenHash=cakes_token_hash($requestId);
    cakes_ensure_orders_table($pdo);
    $existing=$pdo->prepare('SELECT id_venta, payload_hash FROM cakes_web_orders WHERE token_hash=?');
    $existing->execute([$tokenHash]);
    if ($prior=$existing->fetch()) {
        if (!hash_equals($prior['payload_hash'],$payloadHash)) throw new InvalidArgumentException('Este pedido ya fue enviado. Empieza un nuevo diseño para crear otro.');
        echo json_encode(['status'=>'success','id'=>(int)$prior['id_venta'],'count'=>null,'tracking_token'=>$requestId],JSON_UNESCAPED_UNICODE);
        exit;
    }
    $rawItems=$in['items']??[$in];
    if (!is_array($rawItems) || !array_is_list($rawItems) || count($rawItems)<1 || count($rawItems)>20) throw new InvalidArgumentException('Selecciona entre 1 y 20 cakes.');
    $catalog=cake_catalog(); $items=[]; $total=0.0;
    foreach ($rawItems as $rawItem) { if (!is_array($rawItem)) throw new InvalidArgumentException('Cake inválido.'); $item=quote_cake($rawItem,$catalog); $total+=$item['precio']; $items[]=$item; }
    if (!is_numeric($in['total']??null) || abs((float)$in['total']-$total)>0.009) throw new InvalidArgumentException('El precio cambió. Actualiza el pedido.');
    $name=trim((string)($in['cliente_nombre']??'')); $phone=trim((string)($in['cliente_telefono']??'')); if ($name===''||mb_strlen($name)>100||$phone===''||mb_strlen($phone)>40) throw new InvalidArgumentException('Nombre y teléfono son obligatorios.');
    $date=trim((string)($in['fecha_reserva']??'')); if ($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date) || !checkdate((int)substr($date,5,2),(int)substr($date,8,2),(int)substr($date,0,4)))) throw new InvalidArgumentException('Fecha inválida.');
    $note=mb_substr(trim((string)($in['nota']??'')),0,180);
    $empresa=(int)$config['id_empresa']; $sucursal=6; $almacen=7; $uuid=uniqid('CAKE-',true); $now=date('Y-m-d H:i:s'); $fechaReserva=$date!==''?$date.' 12:00:00':$now; $pdo->beginTransaction();
    $cab=$pdo->prepare('INSERT INTO ventas_cabecera (uuid_venta,fecha,total,metodo_pago,id_sucursal,id_almacen,id_caja,tipo_servicio,cliente_nombre,cliente_telefono,cliente_direccion,id_empresa,mensajero_nombre,fecha_reserva,notas,estado_reserva,sincronizado,id_sesion_caja,abono,codigo_pago,estado_pago,canal_origen,moneda,tipo_cambio,monto_moneda_original) VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 0, ?, ?, ?, ?, ?, ?, ?)');
    $cab->execute([$uuid,$now,$total,'PENDIENTE_ENTREGA',$sucursal,$almacen,'reserva',$name,$phone,'',$empresa,'',$fechaReserva,$note!==''?$note:'Pedido web de cake a medida','PENDIENTE',0,'','pendiente','Web Cakes','CUP',1,$total]); $id=(int)$pdo->lastInsertId();
    $sku='CAKE-A-MEDIDA'; $det=$pdo->prepare('INSERT INTO ventas_detalle (id_venta_cabecera,id_producto,cantidad,precio,costo_unitario,nombre_producto,codigo_producto) VALUES (?,?,?,?,?,?,?)');
    foreach ($items as $item) $det->execute([$id,$sku,1,$item['precio'],0,mb_substr($item['descripcion'],0,200),$sku]);
    $options=['items'=>$items,'nota'=>$note,'fecha_solicitada'=>$date];
    $saveOrder=$pdo->prepare('INSERT INTO cakes_web_orders (id_venta,id_empresa,token_hash,payload_hash,opciones,creado) VALUES (?,?,?,?,?,?)');
    try { $saveOrder->execute([$id,$empresa,$tokenHash,$payloadHash,json_encode($options,JSON_UNESCAPED_UNICODE),$now]); }
    catch (PDOException $conflict) {
        if ((string)$conflict->getCode()!=='23000') throw $conflict;
        $pdo->rollBack(); $existing->execute([$tokenHash]); $prior=$existing->fetch();
        if (!$prior || !hash_equals($prior['payload_hash'],$payloadHash)) throw new InvalidArgumentException('Este pedido ya fue enviado. Empieza un nuevo diseño para crear otro.');
        echo json_encode(['status'=>'success','id'=>(int)$prior['id_venta'],'count'=>null,'tracking_token'=>$requestId],JSON_UNESCAPED_UNICODE); exit;
    }
    $pdo->commit();
    $result=['status'=>'success','id'=>$id,'total'=>$total,'count'=>null,'tracking_token'=>$requestId];
    try { $result['count']=cake_activity_count($pdo, $empresa); } catch (Throwable $countError) { error_log('Cakes counter: '.$countError->getMessage()); }
    try { if (file_exists(__DIR__.'/push_notify.php')) { require_once __DIR__.'/push_notify.php'; push_notify($pdo,'operador','🎂 Nuevo cake a medida','#'.$id.' — '.$name.' — '.number_format($total,2).' CUP','/marinero/reservas.php','cake_builder_new'); } } catch (Throwable $ignored) {}
    echo json_encode($result,JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) { if (isset($pdo)&&$pdo->inTransaction()) $pdo->rollBack(); if (http_response_code()<400) http_response_code($e instanceof InvalidArgumentException?422:500); if (!($e instanceof InvalidArgumentException)) error_log('Cakes order: '.$e->getMessage()); echo json_encode(['status'=>'error','msg'=>$e instanceof InvalidArgumentException?$e->getMessage():'No se pudo procesar el pedido. Inténtalo de nuevo.'],JSON_UNESCAPED_UNICODE); }
