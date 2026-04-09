<?php
$data = [
    'accion' => 'ajuste',
    'motivo' => 'Test de ajuste',
    'usuario' => 'Admin',
    'items' => [
        ['sku' => 'TEST01', 'cantidad' => 10]
    ]
];

$ch = curl_init('http://localhost/pos.php?inventario_api=1');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
$response = curl_exec($ch);
curl_close($ch);
echo "Response: $response\n";
