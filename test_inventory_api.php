<?php
require 'db.php';

$sku = '220002';
$alm = 1;

$sq = $pdo->prepare("SELECT cantidad FROM stock_almacen WHERE id_producto=? AND id_almacen=?");
$sq->execute([$sku, $alm]);
$stockAnt = floatval($sq->fetchColumn()?:0);

echo "Stock anterior: $stockAnt\n";

$data = [
    'accion' => 'ajuste',
    'motivo' => 'Test Ajuste',
    'usuario' => 'Admin',
    'items' => [['sku' => $sku, 'cantidad' => 2]]
];

$opts = [
    'http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/json\r\n",
        'content' => json_encode($data),
        'ignore_errors' => true
    ],
    'ssl' => [
        'verify_peer' => false,
        'verify_peer_name' => false,
    ]
];

$context = stream_context_create($opts);
$result = file_get_contents('https://127.0.0.1/pos.php?inventario_api=1', false, $context);
echo "Response Ajuste: $result\n";

$sq->execute([$sku, $alm]);
$stockDespues = floatval($sq->fetchColumn()?:0);
echo "Stock Despues de Ajuste (+2): $stockDespues\n";

$data['accion'] = 'merma';
$data['items'][0]['cantidad'] = 1;
$opts['http']['content'] = json_encode($data);
$context = stream_context_create($opts);
$result = file_get_contents('https://127.0.0.1/pos.php?inventario_api=1', false, $context);
echo "Response Merma: $result\n";

$sq->execute([$sku, $alm]);
$stockFinal = floatval($sq->fetchColumn()?:0);
echo "Stock Final de Merma (-1): $stockFinal\n";

$data['accion'] = 'ajuste';
$data['items'][0]['cantidad'] = -1; // back to original
$opts['http']['content'] = json_encode($data);
$context = stream_context_create($opts);
file_get_contents('https://127.0.0.1/pos.php?inventario_api=1', false, $context);
